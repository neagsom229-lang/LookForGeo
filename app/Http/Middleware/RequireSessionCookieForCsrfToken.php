<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short-circuits /csrf-token for requests with no session cookie BEFORE the
 * session middleware starts, so no session row is persisted for probes.
 */
class RequireSessionCookieForCsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        // The test harness injects sessions without real cookies.
        $testing = app()->environment('testing');

        if ($request->path() === 'csrf-token'
            && ! $request->hasCookie((string) config('session.cookie', 'laravel_session'))
            && ! $testing) {
            return response()->json([
                'success' => false,
                'code' => 'NO_SESSION',
                'message' => 'Please log in again.',
            ], 401)->header('Cache-Control', 'no-store');
        }

        return $next($request);
    }
}
