<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $enrollment = $user->activeEnrollment()->with('exam')->first();

        $entitlements = Entitlements::for($user);

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

            // The full entitlement map, so the client can render locks before
            // making a request. A feature that appears to work and then fails is
            // how a paywall loses someone who was willing to pay.
            'entitlements' => $entitlements->toArray(),

            // Retained for older clients; `entitlements.limits` is authoritative.
            'quota' => [
                'free_lessons_per_day' => $entitlements->limit('lessons_per_day'),
                'used_today' => $entitlements->lessonsUsedToday(),
                'remaining' => $entitlements->lessonsRemaining(),
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
