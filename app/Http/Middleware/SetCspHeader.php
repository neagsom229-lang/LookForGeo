<?php

namespace App\Http\Middleware;

use Closure;

class SetCspHeader
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);
        
        // Set the permissive CSP header
        $response->headers->set('Content-Security-Policy', "script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; img-src * data:;");
        
        return $response;
    }
}