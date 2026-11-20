<?php

namespace App\Http\Middleware;

use Closure;

/**
 * HSTS is only emitted for production requests. A one-year max-age matches
 * the browser preload requirement; includeSubDomains covers any subdomain
 * of the configured APP_URL.
 */
class ForceHsts
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        // Behind Render's TLS-terminating proxy the request itself may not
        // report secure(), so honor the forwarded proto header too.
        $isHttps = $request->secure()
            || strtolower((string) $request->header('X-Forwarded-Proto')) === 'https';

        if (config('app.env') === 'production' && $isHttps) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        return $response;
    }
}
