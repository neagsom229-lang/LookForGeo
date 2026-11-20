<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The single retry layer: one retry on the primary model, then one fallback
 * attempt, sharing a 90s budget, with no request started in the final 15s.
 */
class GeminiBudgetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'AIzaSyFakeBudgetTestKey1234567890',
            'services.gemini.timeout' => 30,
            'services.gemini.retries' => 1,
            'services.gemini.max_total_time' => 90,
            'services.gemini.min_attempt_seconds' => 15,
            'services.gemini.retry_backoff_ms' => 0, // no real sleep in tests
            'services.gemini.model' => 'gemini-3.6-flash',
            'services.gemini.fallback_model' => 'gemini-3.5-flash',
        ]);
    }

    public function test_one_retry_on_primary_then_one_fallback(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-3.6-flash')) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['landmark_name' => 'Fallback Result']),
                    ]]],
                ]],
            ], 200);
        });

        $result = app(GeminiClient::class)->analyzeWithFallback('img', 'prompt');

        $this->assertSame('Fallback Result', $result['landmark_name']);
        // Primary twice (initial + one retry), fallback once. Never 2 retries.
        Http::assertSentCount(3);
    }

    public function test_payload_has_no_deprecated_sampling_fields_and_uses_low_thinking(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['landmark_name' => 'X']),
                    ]]],
                ]],
            ], 200),
        ]);

        app(GeminiClient::class)->analyzeWithFallback('img', 'prompt');

        Http::assertSent(function ($request) {
            $config = $request->data()['generationConfig'] ?? [];

            return ! array_key_exists('temperature', $config)
                && ! array_key_exists('topP', $config)
                && ! array_key_exists('topK', $config)
                && ($config['thinkingConfig']['thinkingLevel'] ?? null) === 'LOW'
                && $request->hasHeader('x-goog-api-key')
                && ! str_contains($request->url(), 'key=');
        });
    }

    public function test_environment_defaults_are_50s_request_and_110s_budget(): void
    {
        $config = require config_path('services.php');

        $this->assertSame(50, $config['gemini']['timeout']);
        $this->assertSame(110, $config['gemini']['max_total_time']);
        $this->assertSame(15, $config['gemini']['min_attempt_seconds']);
    }

    public function test_fallback_is_attempted_when_minimum_window_still_remains(): void
    {
        config(['services.gemini.max_total_time' => 16, 'services.gemini.retries' => 0]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-3.6-flash')) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['landmark_name' => 'Fallback']),
                    ]]],
                ]],
            ], 200);
        });

        $result = app(GeminiClient::class)->analyzeWithFallback('img', 'prompt');

        $this->assertSame('Fallback', $result['landmark_name']);
        Http::assertSentCount(2);
    }

    public function test_no_request_started_when_budget_below_minimum_window(): void
    {
        config(['services.gemini.max_total_time' => 5]); // below the 15s floor

        Http::fake();

        $result = app(GeminiClient::class)->analyzeWithFallback('img', 'prompt');

        $this->assertSame('time_budget_exhausted', $result['error']);
        Http::assertNothingSent();
    }

    public function test_retry_callback_receives_increasing_numbers(): void
    {
        Http::fake(function ($request) {
            return Http::response(['error' => 'unavailable'], 503);
        });

        $retries = [];
        app(GeminiClient::class)->analyzeWithFallback('img', 'prompt', function ($model, $number) use (&$retries) {
            $retries[] = $number;
        });

        // Retry 1 on primary, retry 2 before fallback.
        $this->assertSame([1, 2], $retries);
    }
}
