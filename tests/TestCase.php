<?php

namespace Tests;

use App\Services\Gemini\GeminiCircuitBreaker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Cross-process Nominatim limiter must not leak between tests.
        RateLimiter::clear('nominatim_request');

        // Breaker state is shared via the cache store; isolate each test.
        if (app()->bound(GeminiCircuitBreaker::class)) {
            app(GeminiCircuitBreaker::class)->reset();
        }
    }
}
