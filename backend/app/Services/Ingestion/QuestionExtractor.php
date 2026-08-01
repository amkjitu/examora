<?php

namespace App\Services\Ingestion;

use App\Models\Examora\DocumentPage;
use App\Models\Examora\ExtractedQuestion;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\SourceDocument;
use App\Models\Examora\Topic;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\PromptContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns transcribed page text into staged questions.
 *
 * Everything produced here lands in `extracted_questions` with a status of
 * `pending`. Nothing reaches `past_questions` without a human approving it —
 * see DocumentReviewer.
 */
class QuestionExtractor
{
    private const SYSTEM = <<<'TXT'
    You extract multiple-choice questions from exam paper text.

    Rules:
    1. Extract only questions that are actually present. Never invent one, and
       never complete a question that is cut off — skip it instead.
    2. Preserve the original wording and script exactly. Do not translate.
    3. Set correct_index only when the paper states the answer. If the answer is
       not printed, return null. A guessed answer key is worse than no key.
    4. confidence reflects how sure you are of the transcription: 1.0 for clean
       text, below 0.7 when characters are ambiguous or the layout is broken.
    5. Drop headers, instructions, and anything that is not a question.

    Return JSON only.
    TXT;

    private const CLASSIFY_SYSTEM = <<<'TXT'
    You assign an exam question to one syllabus topic from a supplied list.

    Return the slug of the single best match and a confidence between 0 and 1.
    If no candidate genuinely fits, return null for topic_slug rather than
    forcing a match — an unclassified question is easy to fix in review, a
    wrongly classified one silently corrupts topic importance.

    Return JSON only.
    TXT;

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return int number of questions staged
     */
    public function extract(SourceDocument $document): int
    {
        $topics = $this->topicsFor($document);
        $staged = 0;

        foreach ($document->pages()->get() as $page) {
            if ($page->looksEmpty()) {
                continue;
            }

            foreach ($this->questionsOnPage($document, $page) as $candidate) {
                if ($this->stage($document, $page, $candidate, $topics)) {
                    $staged++;
                }
            }
        }

        return $staged;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function questionsOnPage(SourceDocument $document, DocumentPage $page): array
    {
        $context = [
            'exam' => $document->exam?->name,
            'page_number' => $page->page_number,
            'session_label' => $document->session_label,
            'year' => $document->year,
            'page_text' => $page->text,
        ];

        $request = LlmRequest::make(
            'document.parse',
            "Extract every multiple-choice question from this page.\n\n".PromptContext::block($context),
            self::SYSTEM,
        )
            ->withSchema([
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'stem' => ['type' => 'string'],
                                'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'correct_index' => ['type' => 'integer', 'nullable' => true],
                                'explanation' => ['type' => 'string', 'nullable' => true],
                                'difficulty' => ['type' => 'string', 'enum' => ['easy', 'medium', 'hard']],
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['stem', 'options'],
                        ],
                    ],
                ],
                'required' => ['questions'],
            ])
            ->forUser($document->user_id);

        return $this->ai->complete($request)->json()['questions'] ?? [];
    }

    private function stage(
        SourceDocument $document,
        DocumentPage $page,
        array $candidate,
        Collection $topics,
    ): bool {
        $stem = trim((string) ($candidate['stem'] ?? ''));
        $options = array_values(array_filter(
            array_map(fn ($o) => trim((string) $o), $candidate['options'] ?? []),
            fn ($o) => $o !== '',
        ));

        // A question with fewer than two options is a transcription failure, not
        // a question. Staging it would only waste a reviewer's attention.
        if ($stem === '' || count($options) < 2) {
            return false;
        }

        $fingerprint = PastQuestion::fingerprintFor($stem, $options);

        // Already staged from this document on an earlier run.
        if (ExtractedQuestion::where('source_document_id', $document->id)
            ->where('fingerprint', $fingerprint)
            ->exists()) {
            return false;
        }

        $duplicate = PastQuestion::where('exam_id', $document->exam_id)
            ->where('fingerprint', $fingerprint)
            ->first();

        $correctIndex = $candidate['correct_index'] ?? null;
        if ($correctIndex !== null && ($correctIndex < 0 || $correctIndex >= count($options))) {
            $correctIndex = null;
        }

        $classification = $duplicate
            ? ['topic_id' => $duplicate->topic_id, 'confidence' => 1.0]
            : $this->classify($stem, $options, $topics, $document);

        DB::transaction(function () use (
            $document,
            $page,
            $stem,
            $options,
            $correctIndex,
            $candidate,
            $fingerprint,
            $duplicate,
            $classification
        ) {
            ExtractedQuestion::create([
                'source_document_id' => $document->id,
                'exam_id' => $document->exam_id,
                'topic_id' => $classification['topic_id'],
                'page_number' => $page->page_number,
                'year' => $document->year,
                'session_label' => $document->session_label,
                'stem' => $stem,
                'options' => $options,
                'correct_index' => $correctIndex,
                'explanation' => $candidate['explanation'] ?? null,
                'difficulty' => in_array($candidate['difficulty'] ?? '', ['easy', 'medium', 'hard'], true)
                    ? $candidate['difficulty']
                    : 'medium',
                'confidence' => isset($candidate['confidence']) ? (float) $candidate['confidence'] : null,
                'topic_confidence' => $classification['confidence'],
                // A duplicate is flagged, not discarded: seeing that a question
                // repeats across sessions is the signal, and the reviewer may
                // still want to record the new year against it.
                'status' => $duplicate
                    ? ExtractedQuestion::STATUS_DUPLICATE
                    : ExtractedQuestion::STATUS_PENDING,
                'fingerprint' => $fingerprint,
                'duplicate_of_id' => $duplicate?->id,
                'meta' => ['extraction_method' => $page->extraction_method],
            ]);
        });

        return true;
    }

    /**
     * @return array{topic_id: ?int, confidence: ?float}
     */
    private function classify(string $stem, array $options, Collection $topics, SourceDocument $document): array
    {
        if ($topics->isEmpty()) {
            return ['topic_id' => null, 'confidence' => null];
        }

        $context = [
            'stem' => $stem,
            'options' => $options,
            'candidates' => $topics->map(fn (Topic $t) => [
                'slug' => $t->slug,
                'name' => $t->name,
                'subject' => $t->subject?->name,
            ])->values()->all(),
        ];

        try {
            $result = $this->ai->complete(
                LlmRequest::make(
                    'topic.classify',
                    'Classify this question.'."\n\n".PromptContext::block($context),
                    self::CLASSIFY_SYSTEM,
                )
                    ->withSchema([
                        'type' => 'object',
                        'properties' => [
                            'topic_slug' => ['type' => 'string', 'nullable' => true],
                            'confidence' => ['type' => 'number'],
                        ],
                        'required' => ['topic_slug'],
                    ])
                    ->forUser($document->user_id)
            )->json();
        } catch (\Throwable) {
            return ['topic_id' => null, 'confidence' => null];
        }

        $slug = $result['topic_slug'] ?? null;
        $topic = $slug ? $topics->firstWhere('slug', $slug) : null;

        return [
            'topic_id' => $topic?->id,
            'confidence' => $topic ? (float) ($result['confidence'] ?? 0.5) : null,
        ];
    }

    /** @return Collection<int, Topic> */
    private function topicsFor(SourceDocument $document): Collection
    {
        return Topic::query()
            ->with('subject:id,name')
            ->whereHas('subject', fn ($q) => $q->where('exam_id', $document->exam_id))
            // A document scoped to one subject only competes against that
            // subject's topics, which makes classification markedly more accurate.
            ->when($document->subject_id, fn ($q) => $q->where('subject_id', $document->subject_id))
            ->where('is_active', true)
            ->get();
    }
}
