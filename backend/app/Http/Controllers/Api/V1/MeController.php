<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Examora\LearningEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $user->activeEnrollment()->with('exam')->first();

        $freeLimit = (int) config('examora.free_lessons_per_day');
        $usedToday = LearningEvent::where('user_id', $user->id)
            ->where('type', 'lesson_completed')
            ->whereDate('occurred_at', now()->toDateString())
            ->count();

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'avatar' => $user->getAttribute('avatar'),
            'subscription' => [
                'is_premium' => $user->hasPremiumAccess(),
                'on_trial' => $user->onTrial(),
                // Wave's plan() dereferences the subscription relation, which is
                // null for everyone on the free tier.
                'plan' => $user->subscription()->exists() ? $user->plan()?->name : null,
            ],
            'quota' => [
                'free_lessons_per_day' => $freeLimit,
                'used_today' => $usedToday,
                'remaining' => $user->hasPremiumAccess() ? null : max(0, $freeLimit - $usedToday),
            ],
            'active_enrollment' => $enrollment ? [
                'exam' => [
                    'id' => $enrollment->exam->id,
                    'name' => $enrollment->exam->name,
                    'slug' => $enrollment->exam->slug,
                ],
                'target_session' => $enrollment->target_session,
                'days_left' => $enrollment->daysLeft(),
                'daily_minutes_goal' => $enrollment->daily_minutes_goal,
            ] : null,
        ]]);
    }
}
