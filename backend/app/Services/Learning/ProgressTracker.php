<?php

namespace App\Services\Learning;

use App\Models\Examora\Exam;
use App\Models\Examora\LearnerProfile;
use App\Models\Examora\LearningEvent;
use App\Models\Examora\Lesson;
use App\Models\Examora\MemoryItem;
use App\Models\Examora\Question;
use App\Models\Examora\QuizSession;
use App\Models\Examora\TopicMastery;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mastery, XP, streaks and readiness.
 *
 * Mastery is deliberately not just accuracy. A student who answered two easy
 * questions correctly has not mastered a chapter. It blends three things:
 *
 *   accuracy   how often they are right
 *   coverage   how much of the chapter's question bank they have actually seen
 *   retention  whether the items have survived spaced repetition
 */
class ProgressTracker
{
    private const XP_CORRECT = 10;

    private const XP_INCORRECT = 2;      // effort still counts, or students stop trying

    private const XP_LESSON = 25;

    private const XP_STREAK_BONUS = 15;

    private const W_ACCURACY = 0.45;

    private const W_COVERAGE = 0.25;

    private const W_RETENTION = 0.30;

    public function __construct(private readonly RevisionScheduler $scheduler) {}

    public function afterQuiz(QuizSession $session): void
    {
        $answers = $session->answers()->with('question')->get();

        if ($answers->isEmpty()) {
            return;
        }

        $xp = $answers->sum(fn ($a) => $a->is_correct ? self::XP_CORRECT : self::XP_INCORRECT);

        DB::transaction(function () use ($session, $answers, $xp) {
            $topicIds = $answers->pluck('question.topic_id')->filter()->unique();

            foreach ($topicIds as $topicId) {
                $this->recomputeMastery($session->user_id, $session->exam_id, (int) $topicId);
            }

            $this->award($session->user, $session->exam_id, $xp, 'quiz_completed', [
                'quiz_session_id' => $session->id,
                'type' => $session->type,
                'score' => $session->score,
            ]);
        });
    }

    public function afterLesson(User $user, Lesson $lesson): void
    {
        DB::transaction(function () use ($user, $lesson) {
            TopicMastery::updateOrCreate(
                ['user_id' => $user->id, 'topic_id' => $lesson->topic_id],
                [
                    'exam_id' => $lesson->exam_id,
                    'lesson_completed' => true,
                    'lesson_completed_at' => now(),
                    'last_studied_at' => now(),
                ]
            );

            $this->award($user, $lesson->exam_id, self::XP_LESSON, 'lesson_completed', [
                'lesson_id' => $lesson->id,
                'topic_id' => $lesson->topic_id,
            ]);
        });
    }

    public function recomputeMastery(int $userId, int $examId, int $topicId): TopicMastery
    {
        $stats = DB::table('quiz_answers')
            ->join('questions', 'questions.id', '=', 'quiz_answers.question_id')
            ->where('quiz_answers.user_id', $userId)
            ->where('questions.topic_id', $topicId)
            ->selectRaw('count(*) as attempts, sum(case when quiz_answers.is_correct then 1 else 0 end) as correct')
            ->first();

        $attempts = (int) ($stats->attempts ?? 0);
        $correct = (int) ($stats->correct ?? 0);
        $accuracy = $attempts > 0 ? $correct / $attempts : 0.0;

        // Coverage is relative to the bank this student can actually practise
        // from, which includes their own uploads.
        $bankSize = max(1, Question::visibleTo($userId)->active()->where('topic_id', $topicId)->count());
        $seen = DB::table('memory_items')
            ->where('user_id', $userId)
            ->where('topic_id', $topicId)
            ->count();
        $coverage = min(1.0, $seen / $bankSize);

        $items = MemoryItem::where('user_id', $userId)
            ->where('topic_id', $topicId)
            ->get();

        $retention = $items->isEmpty()
            ? 0.0
            : $items->avg(fn ($item) => $this->scheduler->retrievability($item));

        $mastery = self::W_ACCURACY * $accuracy
            + self::W_COVERAGE * $coverage
            + self::W_RETENTION * $retention;

        $record = TopicMastery::firstOrNew(['user_id' => $userId, 'topic_id' => $topicId]);
        $record->fill([
            'exam_id' => $examId,
            'attempts' => $attempts,
            'correct' => $correct,
            'accuracy' => round($accuracy, 4),
            'coverage' => round($coverage, 4),
            'mastery' => round($mastery, 4),
            'last_studied_at' => now(),
        ])->save();

        return $record;
    }

    public function award(User $user, ?int $examId, int $xp, string $type, array $meta = []): LearnerProfile
    {
        $profile = LearnerProfile::firstOrCreate(
            ['user_id' => $user->id, 'exam_id' => $examId],
            ['xp' => 0, 'level' => 1, 'coins' => 0, 'streak_days' => 0]
        );

        $streakBonus = $this->touchStreak($profile);

        $profile->xp += $xp + $streakBonus;
        $profile->coins += (int) round(($xp + $streakBonus) / 5);
        $profile->level = LearnerProfile::levelForXp($profile->xp);
        $profile->save();

        LearningEvent::create([
            'user_id' => $user->id,
            'exam_id' => $examId,
            'type' => $type,
            'xp' => $xp + $streakBonus,
            'meta' => $meta,
            'occurred_at' => now(),
        ]);

        return $profile;
    }

    /** Returns bonus XP if this activity extended the streak. */
    private function touchStreak(LearnerProfile $profile): int
    {
        $today = now()->toDateString();
        $last = $profile->last_active_on?->toDateString();

        if ($last === $today) {
            return 0;
        }

        $profile->streak_days = $last === now()->subDay()->toDateString()
            ? $profile->streak_days + 1
            : 1;

        $profile->longest_streak = max($profile->longest_streak, $profile->streak_days);
        $profile->last_active_on = $today;

        return $profile->streak_days > 1 ? self::XP_STREAK_BONUS : 0;
    }

    /**
     * Estimated exam readiness, 0-100.
     *
     * Weighted by what the exam actually asks: a topic's contribution is its
     * expected question count times the student's mastery of it, over the total
     * expected questions. Ignoring a five-star topic hurts far more than
     * ignoring a one-star one — which is the whole point of the product.
     */
    public function readiness(User $user, int $examId): array
    {
        $rows = DB::table('topic_statistics')
            ->leftJoin('topic_mastery', function ($join) use ($user) {
                $join->on('topic_mastery.topic_id', '=', 'topic_statistics.topic_id')
                    ->where('topic_mastery.user_id', '=', $user->id);
            })
            ->where('topic_statistics.exam_id', $examId)
            ->select(
                'topic_statistics.topic_id',
                'topic_statistics.expected_questions_max as expected',
                'topic_statistics.probability_stars',
                DB::raw('coalesce(topic_mastery.mastery, 0) as mastery')
            )
            ->get();

        if ($rows->isEmpty()) {
            return ['readiness' => 0.0, 'estimated_score' => 0, 'covered_topics' => 0, 'total_topics' => 0];
        }

        $expectedTotal = max(1, $rows->sum(fn ($r) => max(1, (int) $r->expected)));
        $earned = $rows->sum(fn ($r) => max(1, (int) $r->expected) * (float) $r->mastery);

        $readiness = round(($earned / $expectedTotal) * 100, 2);
        $exam = Exam::find($examId);

        $profile = LearnerProfile::firstOrCreate(
            ['user_id' => $user->id, 'exam_id' => $examId],
            ['xp' => 0, 'level' => 1]
        );

        $profile->update([
            'readiness' => $readiness,
            'estimated_score' => (int) round(($readiness / 100) * ($exam?->total_marks ?? 100)),
            'readiness_computed_at' => now(),
        ]);

        return [
            'readiness' => $readiness,
            'estimated_score' => $profile->estimated_score,
            'covered_topics' => $rows->where('mastery', '>', 0.6)->count(),
            'total_topics' => $rows->count(),
        ];
    }
}
