<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\StudyPlanItem;
use App\Services\Learning\DailyPlanner;
use App\Services\Learning\ProgressTracker;
use App\Services\Learning\RevisionScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DailyPlanner $planner,
        private readonly ProgressTracker $progress,
        private readonly RevisionScheduler $scheduler,
    ) {}

    /**
     * Everything the home screen needs in one round trip: target, days left,
     * today's plan, streak, readiness, weakest topics.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $user->activeEnrollment()->with('exam')->first();

        if (! $enrollment) {
            return response()->json([
                'data' => null,
                'message' => 'Pick an exam to get started.',
            ]);
        }

        $plan = $this->planner->planFor($user, $enrollment, force: $request->boolean('refresh'));
        $profile = $user->profileFor($enrollment->exam_id);
        $readiness = $this->progress->readiness($user, $enrollment->exam_id);

        $weakest = $user->topicMastery()
            ->where('exam_id', $enrollment->exam_id)
            ->where('attempts', '>', 0)
            ->orderBy('mastery')
            ->with('topic:id,name,slug')
            ->limit(5)
            ->get()
            ->map(fn ($m) => [
                'topic' => ['id' => $m->topic?->id, 'name' => $m->topic?->name, 'slug' => $m->topic?->slug],
                'accuracy' => round($m->accuracy * 100),
                'mastery' => round($m->mastery, 2),
            ]);

        return response()->json(['data' => [
            'exam' => [
                'id' => $enrollment->exam->id,
                'name' => $enrollment->exam->name,
                'slug' => $enrollment->exam->slug,
                'total_marks' => $enrollment->exam->total_marks,
            ],
            'target' => [
                'session' => $enrollment->target_session,
                'date' => $enrollment->target_date?->toDateString(),
                'days_left' => $enrollment->daysLeft(),
            ],
            'today' => [
                'target_minutes' => $plan->target_minutes,
                'completed_minutes' => $plan->completed_minutes,
                'items' => $plan->items->map(fn (StudyPlanItem $item) => [
                    'id' => $item->id,
                    'type' => $item->type,
                    'status' => $item->status,
                    'estimated_minutes' => $item->estimated_minutes,
                    'reason' => $item->reason,
                    'topic' => $item->topic ? [
                        'id' => $item->topic->id,
                        'name' => $item->topic->name,
                        'slug' => $item->topic->slug,
                    ] : null,
                    'lesson_id' => $item->lesson_id,
                    'payload' => $item->payload,
                ]),
            ],
            'gamification' => [
                'xp' => $profile->xp,
                'level' => $profile->level,
                'coins' => $profile->coins,
                'streak_days' => $profile->streak_days,
                'longest_streak' => $profile->longest_streak,
                'level_progress' => $profile->xpIntoLevel(),
            ],
            'readiness' => $readiness,
            'revision_due' => $this->scheduler->dueCount($user, $enrollment->exam_id),
            'weakest_topics' => $weakest,
        ]]);
    }

    public function completePlanItem(Request $request, StudyPlanItem $item): JsonResponse
    {
        abort_unless($item->plan->user_id === $request->user()->id, 403);

        $this->planner->completeItem($item);

        return response()->json(['data' => ['id' => $item->id, 'status' => $item->status]]);
    }
}
