<?php

namespace App\Http\Middleware;

use Closure;

/**
 * HSTS is emitted for production requests over HTTPS. A one-year max-age
 * matches industry best practice; includeSubDomains covers any subdomain
 * of the configured APP_URL. The "preload" directive is omitted because
 * it was not explicitly chosen for this project — adding it later is a
 * simple config change if the browser preload list is desired.
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
                'max-age=31536000; includeSubDomains'
            );
        }

        return $response;
    }
}
