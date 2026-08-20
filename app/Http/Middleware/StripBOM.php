<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class StripBOM
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        
        // Strip UTF-8 BOM from JSON responses
        if (str_contains($response->headers->get('Content-Type'), 'application/json')) {
            $content = $response->getContent();
            if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
                $response->setContent(substr($content, 3));
            }
        }
        
        return $response;
    }
}