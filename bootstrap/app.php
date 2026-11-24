<?php

use App\Http\Middleware\AuthenticateAny;
use App\Http\Middleware\ForceCsp;
use App\Http\Middleware\ForceHsts;
use App\Http\Middleware\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

 // ✅ mandatory

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->prepend(ForceCsp::class);
        // Correlation id for every request; exposed as X-Request-ID and
        // attached to every production log line by RequestIdProcessor.
        $middleware->prepend(RequestId::class);
        $middleware->prepend(ForceHsts::class);

        // Accepts web session OR sanctum bearer token (Phase 0 — auth hardening)
        $middleware->alias([
            'auth.any' => AuthenticateAny::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Anonymous API requests must always get a 401 JSON response --
        // never a browser-style 302 to /login, regardless of the Accept header.
        // Web routes keep their default redirect behavior.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });

        // A stale CSRF token gets one stable JSON message, never a debug
        // trace, file path or exception class (even with APP_DEBUG=true).
        $exceptions->render(function (Throwable $e, $request) {
            $isCsrf = $e instanceof TokenMismatchException
                || ($e instanceof HttpException
                    && $e->getStatusCode() === 419);
            if ($isCsrf && ($request->expectsJson() || $request->is('api/*'))) {
                return response()->json([
                    'success' => false,
                    'code' => 'SESSION_EXPIRED',
                    'message' => 'Your session expired, reload the page',
                ], 419);
            }
        });
    })
    ->create();
