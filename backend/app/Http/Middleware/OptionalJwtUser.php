<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Resolves the caller from a bearer token when one is present, without
 * requiring it.
 *
 * Some routes are deliberately public — the exam catalogue, the chapter
 * rankings, the topic forecast — because they are the clearest argument for
 * signing up. But they also personalise when they can: mastery per chapter,
 * entitlements on the forecast.
 *
 * Without this, `$request->user()` is null on those routes even for a signed-in
 * caller with a perfectly good token, because nothing ever parses it. That is
 * not a hypothetical: the chapter list reported 0% mastery for every topic to
 * every student, and because the UI only renders mastery above zero, it looked
 * like a feature that had simply never been built.
 *
 * Fails open by design. A malformed or expired token leaves the request
 * anonymous rather than rejected, which is what "optional" has to mean.
 */
class OptionalJwtUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            try {
                JWTAuth::parseToken()->authenticate();
            } catch (Throwable) {
                // Anonymous, not rejected.
            }
        }

        return $next($request);
    }
}
