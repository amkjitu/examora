<?php

namespace App\Services\Learning;

use App\Models\Examora\Question;
use App\Models\Examora\QuizSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Post-exam analysis.
 *
 * A score alone tells a candidate nothing they can act on. Four questions matter
 * after a mock, and each section answers exactly one:
 *
 *   Where did I lose the marks?      subject breakdown
 *   What did I get wrong, and why?   question review
 *   Where did my time go?            pacing
 *   Am I closer to passing?          readiness delta
 */
class MockAnalyser
{
    /** Above this, a question consumed an unusual share of the clock. */
    private const SLOW_MULTIPLIER = 1.5;

    public function __construct(private readonly ProgressTracker $progress) {}

    public function analyse(QuizSession $session): array
    {
        $session->loadMissing('exam');

        $answers = $session->answers()->get()->keyBy('question_id');

        $questions = Question::visibleTo($session->user_id)
            ->with('topic.subject')
            ->whereIn('id', $session->question_ids)
            ->get()
            ->keyBy('id');

        // Preserve the order the paper was served in — question 7 must be the
        // seventh question the student saw.
        $ordered = collect($session->question_ids)
            ->map(fn ($id) => $questions[$id] ?? null)
            ->filter()
            ->values();

        return [
            'summary' => $this->summary($session, $ordered, $answers),
            'subjects' => $this->subjectBreakdown($session, $ordered, $answers),
            'review' => $this->review($ordered, $answers),
            'pacing' => $this->pacing($session, $ordered, $answers),
            'readiness' => $this->readinessDelta($session),
        ];
    }

    private function summary(QuizSession $session, Collection $questions, Collection $answers): array
    {
        $meta = $session->meta ?? [];
        $total = $questions->count();
        $attempted = $meta['attempted'] ?? $answers->whereNotNull('selected_index')->count();

        return [
            'mode' => $session->mode(),
            'total' => $total,
            'attempted' => $attempted,
            'skipped' => $meta['skipped'] ?? ($total - $attempted),
            'correct' => $session->correct,
            'wrong' => $meta['wrong'] ?? max(0, $attempted - $session->correct),
            'marks' => $meta['marks'] ?? $session->correct,
            'max_marks' => $meta['max_marks'] ?? $total,
            'penalty_marks' => $meta['penalty_marks'] ?? 0,
            'negative_marking' => $meta['negative_marking'] ?? 0,
            'score_percent' => (float) $session->score,
            'duration_seconds' => $session->duration_seconds,
            'allowed_seconds' => isset($meta['duration_minutes']) ? $meta['duration_minutes'] * 60 : null,
            // Accuracy on what they actually attempted, which is the number that
            // tells them whether guessing is costing them under negative marking.
            'accuracy_on_attempted' => $attempted > 0
                ? round($session->correct / $attempted, 4)
                : 0.0,
        ];
    }

    /**
     * Score per subject against that subject's weight in the real exam — the
     * view that shows where the marks were actually lost.
     */
    private function subjectBreakdown(QuizSession $session, Collection $questions, Collection $answers): array
    {
        $penalty = $session->negativeMarking();

        return $questions
            ->groupBy(fn (Question $q) => $q->topic?->subject?->name ?? 'Unclassified')
            ->map(function (Collection $group, string $subject) use ($answers, $penalty) {
                $total = $group->count();
                $correct = 0;
                $attempted = 0;

                foreach ($group as $question) {
                    $answer = $answers[$question->id] ?? null;

                    if ($answer && $answer->selected_index !== null) {
                        $attempted++;

                        if ($answer->is_correct) {
                            $correct++;
                        }
                    }
                }

                $wrong = $attempted - $correct;
                $marks = round($correct - ($wrong * $penalty), 2);

                return [
                    'subject' => $subject,
                    'exam_marks' => $group->first()?->topic?->subject?->marks,
                    'total' => $total,
                    'attempted' => $attempted,
                    'correct' => $correct,
                    'wrong' => $wrong,
                    'skipped' => $total - $attempted,
                    'marks' => $marks,
                    'accuracy' => $attempted > 0 ? round($correct / $attempted, 4) : 0.0,
                    'score_percent' => round(($marks / max(1, $total)) * 100, 2),
                ];
            })
            ->sortBy('score_percent')   // weakest first: that is what to act on
            ->values()
            ->all();
    }

    private function review(Collection $questions, Collection $answers): array
    {
        return $questions->values()->map(function (Question $question, int $index) use ($answers) {
            $answer = $answers[$question->id] ?? null;

            return [
                'position' => $index + 1,
                'question_id' => $question->id,
                'stem' => $question->stem,
                'options' => array_values($question->options ?? []),
                'correct_index' => (int) $question->correct_index,
                'selected_index' => $answer?->selected_index,
                'is_correct' => (bool) ($answer?->is_correct ?? false),
                'skipped' => $answer === null || $answer->selected_index === null,
                'explanation' => $question->explanation,
                'difficulty' => $question->difficulty,
                'origin' => $question->origin,
                'previously_examined' => $question->meta['years'] ?? [],
                'subject' => $question->topic?->subject?->name,
                'topic' => $question->topic ? [
                    'id' => $question->topic->id,
                    'name' => $question->topic->name,
                ] : null,
                'time_ms' => $answer?->time_ms,
                // How the cohort does on this question, for context on whether it
                // was genuinely hard or just hard for them.
                'cohort_p_value' => $question->p_value,
            ];
        })->all();
    }

    /**
     * Time per question, and specifically the questions where time was burned
     * *and* the answer was still wrong. That combination is the actionable one:
     * slow-and-right is fine, fast-and-wrong is carelessness, slow-and-wrong is
     * a topic that needs work before the real paper.
     */
    private function pacing(QuizSession $session, Collection $questions, Collection $answers): array
    {
        $timed = $answers->filter(fn ($a) => $a->time_ms !== null && $a->time_ms > 0);

        if ($timed->isEmpty()) {
            return [
                'median_ms' => null,
                'mean_ms' => null,
                'budget_ms' => null,
                'time_sinks' => [],
                'rushed_mistakes' => [],
            ];
        }

        $times = $timed->pluck('time_ms')->sort()->values();
        $median = (int) $times[(int) floor($times->count() / 2)];
        $mean = (int) round($times->avg());

        $meta = $session->meta ?? [];
        $budget = isset($meta['duration_minutes']) && $questions->count() > 0
            ? (int) round(($meta['duration_minutes'] * 60_000) / $questions->count())
            : null;

        $threshold = ($budget ?? $median) * self::SLOW_MULTIPLIER;

        $byId = $questions->keyBy('id');

        $sinks = $timed
            ->filter(fn ($a) => $a->time_ms >= $threshold && ! $a->is_correct)
            ->sortByDesc('time_ms')
            ->take(8)
            ->map(fn ($a) => [
                'question_id' => $a->question_id,
                'time_ms' => $a->time_ms,
                'stem' => Str::limit($byId[$a->question_id]->stem ?? '', 90),
                'topic' => $byId[$a->question_id]->topic?->name,
            ])
            ->values()
            ->all();

        $rushed = $timed
            ->filter(fn ($a) => $a->time_ms <= ($budget ?? $median) * 0.35 && ! $a->is_correct && $a->selected_index !== null)
            ->sortBy('time_ms')
            ->take(8)
            ->map(fn ($a) => [
                'question_id' => $a->question_id,
                'time_ms' => $a->time_ms,
                'stem' => Str::limit($byId[$a->question_id]->stem ?? '', 90),
                'topic' => $byId[$a->question_id]->topic?->name,
            ])
            ->values()
            ->all();

        return [
            'median_ms' => $median,
            'mean_ms' => $mean,
            'budget_ms' => $budget,
            'time_sinks' => $sinks,
            'rushed_mistakes' => $rushed,
        ];
    }

    /**
     * How this paper moved the estimated score, plus the specific chapters to fix
     * before the next one.
     */
    private function readinessDelta(QuizSession $session): array
    {
        $meta = $session->meta ?? [];
        $after = $this->progress->readiness($session->user, $session->exam_id);

        $before = $meta['readiness_before'] ?? $after['readiness'];
        $scoreBefore = $meta['estimated_score_before'] ?? $after['estimated_score'];

        $fixNext = $session->user->topicMastery()
            ->where('exam_id', $session->exam_id)
            ->where('attempts', '>', 0)
            ->where('mastery', '<', 0.6)
            ->with('topic:id,name,slug')
            ->orderBy('mastery')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'topic' => ['id' => $m->topic?->id, 'name' => $m->topic?->name, 'slug' => $m->topic?->slug],
                'accuracy' => round($m->accuracy * 100),
                'mastery' => round($m->mastery, 2),
            ])
            ->all();

        return [
            'before' => round((float) $before, 2),
            'after' => $after['readiness'],
            'delta' => round($after['readiness'] - (float) $before, 2),
            'estimated_score_before' => (int) $scoreBefore,
            'estimated_score_after' => $after['estimated_score'],
            'exam_total_marks' => $session->exam?->total_marks,
            'fix_next' => $fixNext,
        ];
    }
}
