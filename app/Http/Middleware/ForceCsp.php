<?php

namespace App\Http\Middleware;

use Closure;

class ForceCsp
{
public function handle($request, Closure $next)
{
    // Temporary debug - remove after testing
    \Log::info('ForceCsp middleware is running!');
    
    $response = $next($request);
    $response->headers->set('Content-Security-Policy', 
        "script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net 'unsafe-inline'; " .
        "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; " .
        "font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; " .
        "connect-src 'self' https://lookforgeo.onrender.com; " .
        "img-src * data:;"
    );
    return $response;
}
}