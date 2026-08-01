<?php

namespace App\Services\Learning;

use App\Models\Examora\Lesson;
use App\Models\Examora\PastQuestion;
use App\Models\Examora\Question;
use App\Models\Examora\Topic;
use App\Services\Ai\AiGateway;
use App\Services\Ai\Data\LlmRequest;
use App\Services\Ai\PromptContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generates the exam-shaped micro-lesson.
 *
 * The product promise is "teach me only what has appeared before and what is
 * likely to appear again". That promise is kept here, in the retrieval step —
 * the model is never asked to decide what matters. It receives an ordered list
 * of facts that provably appeared in past papers and is asked only to phrase
 * them for fast recall.
 *
 * Lessons are content, not personalisation, so they are generated once per
 * (topic, exam, locale, version) and shared by every student.
 */
class LessonGenerator
{
    public const PROMPT_VERSION = 1;

    private const SYSTEM = <<<'TXT'
    You are an exam coach for competitive examinations. You are not a textbook.

    Rules, in priority order:
    1. Teach ONLY facts present in the supplied context. Never add outside material.
    2. Every key point must be a single, recallable fact — a name, date, number,
       definition or cause. No paragraphs of background.
    3. Order key points by how often they were examined, most-examined first.
    4. Write for a candidate with minutes, not hours. Plain language.
    5. If the context is thin, say so honestly rather than padding.

    Return JSON only.
    TXT;

    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @param  bool  $force  regenerate even if a current lesson exists
     */
    public function generate(Topic $topic, string $locale = 'en', bool $force = false): Lesson
    {
        $examId = $topic->subject->exam_id;

        $existing = Lesson::where('topic_id', $topic->id)
            ->where('exam_id', $examId)
            ->where('locale', $locale)
            ->where('version', self::PROMPT_VERSION)
            ->first();

        if ($existing && ! $force) {
            return $existing->load('cards');
        }

        $facts = $this->facts($topic);
        $context = [
            'exam' => $topic->subject->exam->name,
            'subject' => $topic->subject->name,
            'topic' => $topic->name,
            'statistics' => $this->statisticsFor($topic),
            'facts' => $facts->values()->all(),
        ];

        $prompt = "Write a micro-lesson for the chapter below.\n\n".PromptContext::block($context);

        $request = LlmRequest::make('lesson.generate', $prompt, self::SYSTEM)
            ->withSchema($this->schema());

        $payload = $this->ai->complete($request);
        $data = $payload->json();

        return DB::transaction(function () use ($existing, $topic, $examId, $locale, $data, $payload, $facts) {
            $lesson = $existing ?: new Lesson;

            $lesson->fill([
                'exam_id' => $examId,
                'topic_id' => $topic->id,
                'title' => $data['title'] ?? $topic->name,
                'summary' => $data['summary'] ?? null,
                'locale' => $locale,
                'version' => self::PROMPT_VERSION,
                'estimated_minutes' => max(2, (int) ($data['estimated_minutes'] ?? 5)),
                'key_points' => array_values($data['key_points'] ?? []),
                'status' => 'published',
                'generated_by' => $payload->provider.':'.$payload->model,
                'prompt_hash' => $payload->cacheHit ? null : null,
                'source_refs' => $facts->pluck('past_question_id')->filter()->values()->all(),
                'generated_at' => now(),
            ])->save();

            $lesson->cards()->delete();

            foreach (array_values($data['cards'] ?? []) as $index => $card) {
                $lesson->cards()->create([
                    'position' => $index,
                    'type' => $card['type'] ?? 'fact',
                    'title' => $card['title'] ?? null,
                    'body' => $card['body'] ?? '',
                ]);
            }

            $this->attachPracticeQuestions($lesson, $topic);

            return $lesson->load('cards');
        });
    }

    /**
     * The examined facts for a topic, deduplicated by fingerprint so a question
     * repeated across four sessions becomes one fact with four year-stamps —
     * which is exactly the signal the lesson should lead with.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function facts(Topic $topic, int $limit = 12): Collection
    {
        return PastQuestion::query()
            ->where('topic_id', $topic->id)
            ->orderByDesc('year')
            ->get()
            ->groupBy(fn (PastQuestion $q) => $q->fingerprint ?: $q->id)
            ->map(function (Collection $group) {
                /** @var PastQuestion $first */
                $first = $group->first();
                $correct = $first->correctOption();

                return [
                    'past_question_id' => $first->id,
                    'stem' => $first->stem,
                    'correct_option' => $correct,
                    'answer_fact' => $this->toFact($first->stem, $correct),
                    'distractors' => collect($first->options)
                        ->reject(fn ($o, $i) => $i === (int) $first->correct_index)
                        ->values()->all(),
                    'explanation' => $first->explanation,
                    'difficulty' => $first->difficulty,
                    'years' => $group->pluck('year')->unique()->sortDesc()->values()->all(),
                    'times_examined' => $group->count(),
                ];
            })
            ->sortByDesc(fn ($fact) => [$fact['times_examined'], max($fact['years'] ?: [0])])
            ->take($limit)
            ->values();
    }

    /**
     * Collapse "Who declared independence?" + "Ziaur Rahman" into a declarative
     * fact the student can memorise, rather than a Q/A pair they can only
     * recognise. Recognition is not recall.
     */
    private function toFact(string $stem, ?string $answer): string
    {
        if (! $answer) {
            return $stem;
        }

        $clean = rtrim(trim($stem), '?।.');

        return "{$clean} — {$answer}.";
    }

    private function statisticsFor(Topic $topic): array
    {
        $stats = $topic->statistics;

        return $stats ? [
            'question_count' => $stats->question_count,
            'probability_stars' => $stats->probability_stars,
            'importance_score' => $stats->importance_score,
            'estimated_minutes' => $stats->estimated_minutes,
            'expected_questions' => "{$stats->expected_questions_min}-{$stats->expected_questions_max}",
        ] : [];
    }

    /**
     * Promote the topic's past questions into the practice bank and bind them to
     * this lesson so the post-lesson check uses real examined questions rather
     * than generated approximations.
     */
    private function attachPracticeQuestions(Lesson $lesson, Topic $topic): void
    {
        PastQuestion::where('topic_id', $topic->id)
            ->orderByDesc('year')
            ->limit(20)
            ->get()
            ->each(function (PastQuestion $past) use ($lesson, $topic) {
                // Shared-bank promotion, deterministic regardless of which user
                // happened to trigger the lesson generation.
                Question::withAllOwners()->updateOrCreate(
                    ['past_question_id' => $past->id, 'owner_id' => null],
                    [
                        'exam_id' => $past->exam_id,
                        'topic_id' => $topic->id,
                        'lesson_id' => $lesson->id,
                        'origin' => Question::ORIGIN_PAST,
                        'stem' => $past->stem,
                        'options' => $past->options,
                        'correct_index' => $past->correct_index,
                        'explanation' => $past->explanation,
                        'difficulty' => $past->difficulty,
                        'fingerprint' => $past->fingerprint,
                        'is_active' => true,
                        'meta' => ['years' => [$past->year], 'session' => $past->session_label],
                    ]
                );
            });
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'estimated_minutes' => ['type' => 'integer'],
                'key_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'cards' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => ['fact', 'mnemonic', 'timeline', 'comparison', 'pitfall']],
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                        ],
                        'required' => ['type', 'body'],
                    ],
                ],
            ],
            'required' => ['title', 'summary', 'key_points', 'cards'],
        ];
    }
}
