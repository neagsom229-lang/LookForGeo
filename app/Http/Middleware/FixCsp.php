<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class FixCsp
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Override the restrictive CSP with a permissive one
        $response->headers->set('Content-Security-Policy', "script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src * data:;");

        return $response;
    }
}