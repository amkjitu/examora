<?php

namespace App\Http\Middleware;

use App\Models\Examora\LearningEvent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Free tier is metered, not walled.
 *
 * A candidate who cannot try the product will not subscribe to it, so free users
 * get a real daily allowance of lessons and are told exactly where they stand.
 * Subscribers and trial users pass straight through.
 */
class EnforceLessonQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->hasPremiumAccess()) {
            return $next($request);
        }

        $limit = (int) config('examora.free_lessons_per_day', 3);

        $usedToday = LearningEvent::where('user_id', $user->id)
            ->where('type', 'lesson_completed')
            ->whereDate('occurred_at', now()->toDateString())
            ->count();

        if ($usedToday >= $limit) {
            return response()->json([
                'message' => "You've used all {$limit} free lessons for today.",
                'code' => 'lesson_quota_exceeded',
                'quota' => ['limit' => $limit, 'used' => $usedToday, 'resets_at' => now()->addDay()->startOfDay()->toIso8601String()],
            ], 402);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->headers->set('X-Examora-Lesson-Quota-Limit', (string) $limit);
            $response->headers->set('X-Examora-Lesson-Quota-Used', (string) $usedToday);
        }

        return $response;
    }
}
