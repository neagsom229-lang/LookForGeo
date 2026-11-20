<?php

use App\Http\Middleware\AuthenticateAny;
use App\Http\Middleware\ForceCsp;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(ForceCsp::class);

        // Accepts a web session OR a Sanctum bearer token.
        $middleware->alias([
            'auth.any' => AuthenticateAny::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Anonymous API requests must always get 401 JSON -- never a 302 to
        // /login -- regardless of the Accept header. Web routes keep the
        // default redirect behavior.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })
    ->create();
