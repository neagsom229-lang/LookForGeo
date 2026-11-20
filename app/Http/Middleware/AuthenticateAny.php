<?php

namespace App\Http\Middleware;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

/**
 * Accepts a request authenticated by EITHER:
 *  - the web (session) guard - browser sessions, or
 *  - the sanctum guard - stateless API/mobile clients with a personal access token.
 *
 * Used for the cost-bearing API endpoints so anonymous callers receive 401
 * instead of being able to spend Gemini / paid quota.
 */
class AuthenticateAny extends Middleware
{
    /**
     * @param  Request  $request
     * @return mixed
     *
     * @throws AuthenticationException
     */
    protected function authenticate($request, array $guards)
    {
        if (empty($guards)) {
            $guards = [null];
        }

        foreach ($guards as $guard) {
            $guardName = $guard ?: 'web';

            if ($this->auth->guard($guardName)->check()) {
                return $this->auth->shouldUse($guardName);
            }
        }

        if ($this->auth->guard('sanctum')->check()) {
            return $this->auth->shouldUse('sanctum');
        }

        return $this->unauthenticated($request, $guards);
    }
}
