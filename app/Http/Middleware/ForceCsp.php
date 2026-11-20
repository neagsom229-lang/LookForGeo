<?php

namespace App\Http\Middleware;

use Closure;

class ForceCsp
{
    public function handle($request, Closure $next)
    {
        $response = $next($request);

        $appOrigin = $this->appOrigin();

        $response->headers->set(
            'Content-Security-Policy',
            "script-src 'self' https://cdnjs.cloudflare.com https://unpkg.com https://cdn.jsdelivr.net 'unsafe-inline'; "
            ."style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://unpkg.com; "
            ."font-src 'self' https://cdnjs.cloudflare.com https://fonts.gstatic.com; "
            ."connect-src 'self'".($appOrigin ? ' '.$appOrigin : '').' https://unpkg.com; '
            .'img-src * data:;'
        );

        return $response;
    }

    /**
     * Derive the scheme+host(+port) origin from config('app.url') so the
     * CSP follows the environment instead of a hardcoded deploy host.
     */
    private function appOrigin(): string
    {
        $url = (string) config('app.url', '');
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (! empty($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
