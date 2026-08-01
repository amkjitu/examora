<?php

namespace App\Services\Learning;

use App\Models\Examora\ExamEnrollment;
use App\Models\Examora\Lesson;
use App\Models\Examora\StudyPlan;
use App\Models\Examora\StudyPlanItem;
use App\Models\Examora\Topic;
use App\Models\Examora\TopicStatistic;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds "today's target".
 *
 * Priority order is not negotiable and is the difference between a study app and
 * a coach:
 *
 *   1. Revision that is due. Forgetting what you already learned is the most
 *      expensive thing a candidate can do.
 *   2. New chapters, highest exam-probability first, weighted by how little time
 *      is left. With 90 days you can afford three-star topics; with 10 you
 *      cannot.
 *   3. Practice on weak-but-important topics.
 *   4. A mock, when the exam is close and coverage is broad enough to be worth
 *      measuring.
 */
class DailyPlanner
{
    private const REVISION_MINUTES_PER_ITEM = 0.5;

    private const PRACTICE_MINUTES = 5;

    private const MOCK_MINUTES = 30;

    /** Below this mastery a topic counts as weak. */
    private const WEAK_MASTERY = 0.6;

    public function __construct(private readonly RevisionScheduler $scheduler) {}

    public function planFor(User $user, ExamEnrollment $enrollment, ?Carbon $date = null, bool $force = false): StudyPlan
    {
        $date = ($date ?? now())->startOfDay();

        $plan = StudyPlan::firstOrNew([
            'user_id' => $user->id,
            'exam_id' => $enrollment->exam_id,
            'plan_date' => $date->toDateString(),
        ]);

        if ($plan->exists && ! $force) {
            return $plan->load('items.topic', 'items.lesson');
        }

        $targetMinutes = $enrollment->daily_minutes_goal;
        $daysLeft = $enrollment->daysLeft();

        return DB::transaction(function () use ($plan, $user, $enrollment, $targetMinutes, $daysLeft) {
            $plan->fill([
                'target_minutes' => $targetMinutes,
                'generated_at' => now(),
                'meta' => ['days_left' => $daysLeft],
            ])->save();

            $plan->items()->delete();

            $remaining = $targetMinutes;
            $position = 0;

            // 1. Due revision.
            $dueCount = $this->scheduler->dueCount($user, $enrollment->exam_id);

            if ($dueCount > 0) {
                $minutes = (int) min($remaining, max(2, ceil($dueCount * self::REVISION_MINUTES_PER_ITEM)));

                $plan->items()->create([
                    'type' => StudyPlanItem::TYPE_REVISION,
                    'position' => $position++,
                    'estimated_minutes' => $minutes,
                    'reason' => "{$dueCount} items are due — reviewing them today keeps them for the exam.",
                    'payload' => ['due_count' => $dueCount],
                ]);

                $remaining -= $minutes;
            }

            // 2. New chapters by exam probability.
            foreach ($this->nextTopics($user, $enrollment->exam_id, $daysLeft) as $row) {
                if ($remaining <= 0) {
                    break;
                }

                $minutes = (int) min($remaining, max(3, $row->estimated_minutes));
                $lessonId = Lesson::where('topic_id', $row->topic_id)
                    ->where('exam_id', $enrollment->exam_id)
                    ->value('id');

                $plan->items()->create([
                    'type' => StudyPlanItem::TYPE_LESSON,
                    'topic_id' => $row->topic_id,
                    'lesson_id' => $lessonId,
                    'position' => $position++,
                    'estimated_minutes' => $minutes,
                    'reason' => $this->reasonFor($row),
                    'payload' => [
                        'probability_stars' => (int) $row->probability_stars,
                        'question_count' => (int) $row->question_count,
                    ],
                ]);

                $remaining -= $minutes;
            }

            // 3. Practice on weak, important topics.
            foreach ($this->weakTopics($user, $enrollment->exam_id) as $row) {
                if ($remaining < self::PRACTICE_MINUTES) {
                    break;
                }

                $plan->items()->create([
                    'type' => StudyPlanItem::TYPE_PRACTICE,
                    'topic_id' => $row->topic_id,
                    'position' => $position++,
                    'estimated_minutes' => self::PRACTICE_MINUTES,
                    'reason' => 'Accuracy here is '.round(((float) $row->accuracy) * 100).'% on a '.$row->probability_stars.'-star topic.',
                    'payload' => ['mastery' => (float) $row->mastery],
                ]);

                $remaining -= self::PRACTICE_MINUTES;
            }

            // 4. Mock when the exam is near.
            if ($daysLeft !== null && $daysLeft <= 30 && $remaining >= self::MOCK_MINUTES) {
                $plan->items()->create([
                    'type' => StudyPlanItem::TYPE_MOCK,
                    'position' => $position++,
                    'estimated_minutes' => self::MOCK_MINUTES,
                    'reason' => "{$daysLeft} days left — timed practice now matters more than new material.",
                ]);
            }

            return $plan->load('items.topic', 'items.lesson');
        });
    }

    /**
     * Topics the student has not studied, ordered by importance. When time is
     * short we hard-filter to high-probability topics: covering three-star
     * material with ten days left is a worse use of the time than securing the
     * five-star material.
     */
    private function nextTopics(User $user, int $examId, ?int $daysLeft)
    {
        $minStars = match (true) {
            $daysLeft === null => 1,
            $daysLeft <= 14 => 4,
            $daysLeft <= 45 => 3,
            default => 1,
        };

        return TopicStatistic::query()
            ->from('topic_statistics as ts')
            ->leftJoin('topic_mastery as tm', function ($join) use ($user) {
                $join->on('tm.topic_id', '=', 'ts.topic_id')->where('tm.user_id', '=', $user->id);
            })
            ->where('ts.exam_id', $examId)
            ->where('ts.probability_stars', '>=', $minStars)
            ->where(fn ($q) => $q->whereNull('tm.id')->orWhere('tm.lesson_completed', false))
            ->orderByDesc('ts.importance_score')
            ->select('ts.topic_id', 'ts.probability_stars', 'ts.estimated_minutes', 'ts.question_count', 'ts.importance_score')
            ->limit(5)
            ->get();
    }

    private function weakTopics(User $user, int $examId)
    {
        return DB::table('topic_mastery as tm')
            ->join('topic_statistics as ts', 'ts.topic_id', '=', 'tm.topic_id')
            ->where('tm.user_id', $user->id)
            ->where('tm.exam_id', $examId)
            ->where('tm.attempts', '>', 0)
            ->where('tm.mastery', '<', self::WEAK_MASTERY)
            ->orderByDesc('ts.importance_score')
            ->select('tm.topic_id', 'tm.mastery', 'tm.accuracy', 'ts.probability_stars')
            ->limit(3)
            ->get();
    }

    private function reasonFor(object $row): string
    {
        return "{$row->question_count} questions from this chapter have appeared before ("
            .str_repeat('★', (int) $row->probability_stars).').';
    }

    /** Marks a plan item done and rolls the completed-minutes counter. */
    public function completeItem(StudyPlanItem $item): StudyPlanItem
    {
        if ($item->status === 'completed') {
            return $item;
        }

        $item->update(['status' => 'completed', 'completed_at' => now()]);
        $item->plan()->increment('completed_minutes', $item->estimated_minutes);

        return $item;
    }

    public function topicsWithProbability(int $examId, ?int $subjectId = null, int $limit = 50)
    {
        return DB::table('topics')
            ->join('subjects', 'subjects.id', '=', 'topics.subject_id')
            ->leftJoin('topic_statistics as ts', function ($join) use ($examId) {
                $join->on('ts.topic_id', '=', 'topics.id')->where('ts.exam_id', '=', $examId);
            })
            ->leftJoin('topic_predictions as tp', function ($join) use ($examId) {
                $join->on('tp.topic_id', '=', 'topics.id')->where('tp.exam_id', '=', $examId);
            })
            ->where('subjects.exam_id', $examId)
            ->where('topics.is_active', true)
            ->where('topics.level', Topic::LEVEL_CHAPTER)
            ->when($subjectId, fn ($q) => $q->where('subjects.id', $subjectId))
            ->orderByDesc(DB::raw('coalesce(ts.importance_score, 0)'))
            ->select(
                'topics.id',
                'topics.name',
                'topics.slug',
                'topics.description',
                'subjects.name as subject_name',
                'subjects.slug as subject_slug',
                DB::raw('coalesce(ts.question_count, 0) as question_count'),
                DB::raw('coalesce(ts.probability_stars, 1) as probability_stars'),
                DB::raw('coalesce(ts.importance_score, 0) as importance_score'),
                DB::raw('coalesce(ts.estimated_minutes, 5) as estimated_minutes'),
                'ts.expected_questions_min',
                'ts.expected_questions_max',
                'ts.last_year',
                // Forecast markers for the chapter list: is this topic overdue
                // against its own cycle, and which way is it trending?
                'tp.appearance_probability',
                'tp.confidence as prediction_confidence',
                'tp.trend',
                'tp.overdue_ratio'
            )
            ->limit($limit)
            ->get();
    }
}
