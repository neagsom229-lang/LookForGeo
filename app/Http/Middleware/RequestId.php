<?php

namespace App\Http\Middleware;

use App\Support\RequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accept an inbound X-Request-ID (for distributed tracing) or generate one,
 * expose it on the response and publish it to the log processors.
 */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = trim((string) $request->header('X-Request-ID', ''));
        if ($id === '' || strlen($id) > 128) {
            $id = (string) Str::uuid();
        }

        app(RequestContext::class)->setId($id);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $id);

        return $response;
    }
}
