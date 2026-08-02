<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The free tier is metered, not walled.
 *
 * A candidate who cannot try the product will not subscribe to it, so free users
 * get a real daily allowance of lessons and are told exactly where they stand.
 * The allowance comes from the tier definition rather than a standalone config
 * value, so pricing lives in one place.
 */
class EnforceLessonQuota
{
    public function handle(Request $request, Closure $next): Response
    {
        $entitlements = Entitlements::for($request->user());
        $limit = $entitlements->limit('lessons_per_day');

        // Unlimited.
        if ($limit === null) {
            return $next($request);
        }

        $used = $entitlements->lessonsUsedToday();

        if ($used >= $limit) {
            return response()->json([
                'message' => "You've used all {$limit} lessons on your "
                    .$entitlements->label().' plan for today.',
                'code' => 'lesson_quota_exceeded',
                'current_tier' => $entitlements->tierKey(),
                'upgrade_url' => config('examora.upgrade_url'),
                'quota' => [
                    'limit' => $limit,
                    'used' => $used,
                    'resets_at' => now()->addDay()->startOfDay()->toIso8601String(),
                ],
                // Said explicitly because a quota message that reads like a hard
                // stop makes people leave. Revision is what keeps their marks.
                'still_available' => 'Practice and revision are unlimited on every plan.',
            ], 402);
        }

        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $response->headers->set('X-Examora-Lesson-Quota-Limit', (string) $limit);
            $response->headers->set('X-Examora-Lesson-Quota-Used', (string) $used);
        }

        return $response;
    }
}
