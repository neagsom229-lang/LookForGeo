<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that render a CSRF token into HTML must never be cached by a browser
 * or service worker: a stale token produces 419 on the next upload.
 */
class NoStoreHtml
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $type = strtolower((string) $response->headers->get('Content-Type'));

        if (str_contains($type, 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
