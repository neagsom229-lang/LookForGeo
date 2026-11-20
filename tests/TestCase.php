<?php

namespace Tests;

use App\Services\Gemini\GeminiCircuitBreaker;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Breaker state is shared via the cache store; isolate each test.
        if (app()->bound(GeminiCircuitBreaker::class)) {
            app(GeminiCircuitBreaker::class)->reset();
        }
    }
}
