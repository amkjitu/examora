<?php

namespace App\Http\Middleware;

use App\Support\Entitlements;
use App\Support\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a route unless the caller's tier includes a named feature.
 *
 * Usage: `->middleware('feature:mock_exam')`
 *
 * The 402 body always names the feature and where to upgrade, so a client can
 * render something useful rather than a generic error. It is deliberately
 * specific: "Mock exams are on the Premium plan" converts; "Payment Required"
 * does not.
 */
class RequiresFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $entitlements = Entitlements::for($request->user());

        if ($entitlements->allows($feature)) {
            return $next($request);
        }

        return response()->json([
            'message' => Feature::label($feature).' is not included in your '
                .$entitlements->label().' plan.',
            'code' => 'feature_not_included',
            'feature' => $feature,
            'feature_label' => Feature::label($feature),
            'current_tier' => $entitlements->tierKey(),
            'upgrade_url' => config('examora.upgrade_url'),
        ], 402);
    }
}
