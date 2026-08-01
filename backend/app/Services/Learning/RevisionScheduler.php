<?php

namespace App\Services\Learning;

use App\Models\Examora\MemoryItem;
use App\Models\Examora\Question;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * SM-2 spaced repetition.
 *
 * Two deliberate departures from textbook SM-2:
 *
 * 1. Quality is inferred, not asked. Interrupting a student to rate their own
 *    recall 0-5 is the single biggest source of drop-off in study apps. We
 *    derive it from correctness, answer latency relative to the item's own
 *    history, and an optional confidence tap.
 *
 * 2. Learning steps before graduation. Raw SM-2 jumps a new item to 1 day then
 *    6 days; that loses items a student has only just met. New items get short
 *    same-session steps first (the Anki refinement), then enter the SM-2 curve.
 */
class RevisionScheduler
{
    private const MIN_EASE = 1.30;

    private const AGAIN_THRESHOLD = 3;   // quality < 3 means the item lapsed

    /** Minutes for the learning steps before an item graduates to days. */
    private const LEARNING_STEPS = [1, 10];

    private const FAST_ANSWER_MS = 8_000;

    private const SLOW_ANSWER_MS = 30_000;

    public function record(
        User $user,
        Question $question,
        bool $isCorrect,
        ?int $timeMs = null,
        ?int $confidence = null,
    ): MemoryItem {
        $item = MemoryItem::firstOrNew([
            'user_id' => $user->id,
            'question_id' => $question->id,
        ]);

        if (! $item->exists) {
            $item->fill([
                'topic_id' => $question->topic_id,
                'exam_id' => $question->exam_id,
                'state' => MemoryItem::STATE_NEW,
                'ease_factor' => 2.5,
                'interval_days' => 0,
                'repetitions' => 0,
                'lapses' => 0,
            ]);
        }

        $quality = $this->quality($isCorrect, $timeMs, $confidence);

        return $this->apply($item, $quality);
    }

    /**
     * Map an answer onto SM-2's 0-5 quality scale.
     *
     * correct + fast + confident  -> 5
     * correct + normal            -> 4
     * correct + slow/hesitant     -> 3
     * wrong + close               -> 2
     * wrong                       -> 1
     */
    public function quality(bool $isCorrect, ?int $timeMs, ?int $confidence): int
    {
        if (! $isCorrect) {
            return $confidence !== null && $confidence >= 3 ? 1 : 2;
        }

        $quality = 4;

        if ($timeMs !== null) {
            if ($timeMs <= self::FAST_ANSWER_MS) {
                $quality++;
            } elseif ($timeMs >= self::SLOW_ANSWER_MS) {
                $quality--;
            }
        }

        if ($confidence !== null) {
            $quality += match (true) {
                $confidence >= 4 => 1,
                $confidence <= 1 => -1,
                default => 0,
            };
        }

        return max(3, min(5, $quality));
    }

    public function apply(MemoryItem $item, int $quality): MemoryItem
    {
        $now = now();

        if ($quality < self::AGAIN_THRESHOLD) {
            // Lapse: back to relearning, ease penalised, but never below the floor.
            $item->repetitions = 0;
            $item->lapses++;
            $item->interval_days = 0;
            $item->ease_factor = max(self::MIN_EASE, $item->ease_factor - 0.20);
            $item->state = $item->state === MemoryItem::STATE_NEW
                ? MemoryItem::STATE_LEARNING
                : MemoryItem::STATE_RELEARNING;
            $item->due_at = $now->copy()->addMinutes(self::LEARNING_STEPS[0]);
            $item->last_reviewed_at = $now;
            $item->save();

            return $item;
        }

        // Standard SM-2 ease update.
        $item->ease_factor = max(
            self::MIN_EASE,
            $item->ease_factor + (0.1 - (5 - $quality) * (0.08 + (5 - $quality) * 0.02))
        );

        $inLearning = in_array($item->state, [MemoryItem::STATE_NEW, MemoryItem::STATE_LEARNING, MemoryItem::STATE_RELEARNING], true);

        if ($inLearning && $item->repetitions < count(self::LEARNING_STEPS)) {
            $step = self::LEARNING_STEPS[$item->repetitions];
            $item->repetitions++;
            $item->state = MemoryItem::STATE_LEARNING;
            $item->due_at = $now->copy()->addMinutes($step);
            $item->last_reviewed_at = $now;
            $item->save();

            return $item;
        }

        $item->repetitions++;

        $item->interval_days = match (true) {
            $item->interval_days === 0 => 1,
            $item->interval_days === 1 => 6,
            default => (int) max(1, round($item->interval_days * $item->ease_factor)),
        };

        $item->state = MemoryItem::STATE_REVIEW;
        $item->due_at = $now->copy()->addDays($item->interval_days);
        $item->last_reviewed_at = $now;
        $item->save();

        return $item;
    }

    /**
     * Items the student owes today. Overdue first, then weakest ease — the
     * ordering a candidate under time pressure actually benefits from.
     *
     * @return Collection<int, MemoryItem>
     */
    public function due(User $user, int $examId, int $limit = 20): Collection
    {
        return MemoryItem::query()
            ->where('user_id', $user->id)
            ->where('exam_id', $examId)
            ->due()
            ->orderBy('due_at')
            ->orderBy('ease_factor')
            ->with('question')
            ->limit($limit)
            ->get();
    }

    public function dueCount(User $user, int $examId): int
    {
        return MemoryItem::where('user_id', $user->id)
            ->where('exam_id', $examId)
            ->due()
            ->count();
    }

    /**
     * Probability the student still recalls an item right now, using the
     * standard exponential forgetting curve with the item's own interval as the
     * stability constant. Drives the readiness estimate.
     */
    public function retrievability(MemoryItem $item, ?\DateTimeInterface $at = null): float
    {
        if ($item->last_reviewed_at === null || $item->interval_days <= 0) {
            return 0.0;
        }

        $at = $at ?? now();
        $elapsed = max(0, $item->last_reviewed_at->diffInDays($at));

        return round(exp(-$elapsed / max(1, $item->interval_days)), 4);
    }
}
