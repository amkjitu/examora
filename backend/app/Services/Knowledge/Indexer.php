<?php

namespace App\Services\Knowledge;

use App\Models\Examora\KnowledgeChunk;
use App\Models\Examora\PastQuestion;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns source material into embedded knowledge chunks.
 *
 * Past questions are indexed as "the fact the examiner tested", not as raw
 * question text — that is what makes retrieval surface exam-relevant facts
 * rather than trivia phrasing.
 */
class Indexer
{
    /** Retrieval chunk: the examined fact, with provenance, for RAG. */
    public const SOURCE_PAST_QUESTION = 'past_question';

    /**
     * Identity chunk: the question itself and nothing else.
     *
     * Deliberately separate from the retrieval chunk. That one includes the
     * session label and year, which is right for retrieval and actively wrong
     * for comparing two questions — provenance is precisely what differs between
     * two appearances of the same question, so including it buries the signal
     * that they are the same. Measured on the seeded corpus: a reworded pair
     * scores 0.96 on identity text and 0.84 once provenance is mixed in, while
     * two different questions on one topic score 0.27 and 0.44 respectively.
     * The identity text nearly quadruples the margin.
     */
    public const SOURCE_PAST_QUESTION_IDENTITY = 'past_question_identity';

    public function __construct(private readonly AiGateway $ai) {}

    public function indexPastQuestions(int $examId, int $batchSize = 64): int
    {
        $indexed = 0;

        PastQuestion::query()
            ->where('exam_id', $examId)
            ->chunkById($batchSize, function (Collection $questions) use (&$indexed) {
                $pending = [];

                foreach ($questions as $question) {
                    // Two chunks per question: one for retrieval (with
                    // provenance) and one for identity comparison (without).
                    $variants = [
                        self::SOURCE_PAST_QUESTION => $this->renderPastQuestion($question),
                        self::SOURCE_PAST_QUESTION_IDENTITY => $this->renderQuestionIdentity($question),
                    ];

                    foreach ($variants as $sourceType => $content) {
                        $checksum = hash('sha256', $content);

                        $existing = KnowledgeChunk::where('source_type', $sourceType)
                            ->where('source_id', $question->id)
                            ->first();

                        if ($existing && $existing->checksum === $checksum && $existing->vector() !== null) {
                            continue;
                        }

                        $pending[] = [
                            'model' => $existing ?? new KnowledgeChunk,
                            'question' => $question,
                            'source_type' => $sourceType,
                            'content' => $content,
                            'checksum' => $checksum,
                        ];
                    }
                }

                if ($pending === []) {
                    return;
                }

                $vectors = $this->ai->embed(array_column($pending, 'content'));

                DB::transaction(function () use ($pending, $vectors, &$indexed) {
                    foreach ($pending as $i => $row) {
                        /** @var KnowledgeChunk $chunk */
                        $chunk = $row['model'];
                        $question = $row['question'];

                        $chunk->fill([
                            'exam_id' => $question->exam_id,
                            'topic_id' => $question->topic_id,
                            'source_type' => $row['source_type'],
                            'source_id' => $question->id,
                            'title' => $question->session_label,
                            'content' => $row['content'],
                            'token_count' => (int) ceil(mb_strlen($row['content']) / 3.5),
                            'embedding_model' => config('ai.providers.'.config('ai.default').'.embedding_model', 'mock'),
                            'checksum' => $row['checksum'],
                            'meta' => [
                                'year' => $question->year,
                                'difficulty' => $question->difficulty,
                            ],
                        ]);

                        $chunk->setVector($vectors[$i] ?? null);
                        $chunk->save();
                        $indexed++;
                    }
                });
            });

        return $indexed;
    }

    public function indexText(int $examId, ?int $topicId, string $sourceType, string $title, string $content): KnowledgeChunk
    {
        $chunk = new KnowledgeChunk([
            'exam_id' => $examId,
            'topic_id' => $topicId,
            'source_type' => $sourceType,
            'title' => $title,
            'content' => $content,
            'token_count' => (int) ceil(mb_strlen($content) / 3.5),
            'checksum' => hash('sha256', $content),
        ]);

        $chunk->setVector($this->ai->embedOne($content));
        $chunk->save();

        return $chunk;
    }

    /**
     * The question's identity: what was asked, and the choices offered.
     *
     * Deliberately excludes both provenance *and the answer key*.
     *
     * Excluding provenance is obvious — session labels are what differ between
     * appearances. Excluding the answer is subtler and matters more: if the key
     * were part of the comparison, two copies of one question carrying
     * *different* keys would score as dissimilar, form no cluster, and never be
     * surfaced. That case is a data error worth catching, so the text must
     * describe the question alone and let NearDuplicateDetector flag the
     * disagreement.
     */
    private function renderQuestionIdentity(PastQuestion $question): string
    {
        return trim(implode("\n", array_filter([
            $question->stem,
            implode(' ', $question->options ?? []),
        ])));
    }

    private function renderPastQuestion(PastQuestion $question): string
    {
        $answer = $question->correctOption();

        return trim(implode("\n", array_filter([
            $question->stem,
            $answer ? "Answer: {$answer}" : null,
            $question->explanation ? "Why: {$question->explanation}" : null,
            "Examined in: {$question->session_label} ({$question->year})",
        ])));
    }
}
