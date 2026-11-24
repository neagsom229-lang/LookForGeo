<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retry layer: one retry on the primary for retryable non-quota/overload errors,
 * then one fallback attempt. 503/UNAVAILABLE and 429/quota switch to fallback
 * immediately (honoring Retry-After) to avoid burning same-model budget.
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
            'services.gemini.fallback_model' => 'gemini-3.1-flash-lite',
        ]);
    }

    public function test_503_switches_to_fallback_immediately(): void
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
        // 503/UNAVAILABLE should not burn a same-model retry; fallback wins.
        Http::assertSentCount(2);
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
                && ($config['thinkingConfig']['thinkingLevel'] ?? null) === 'low'
                && $request->hasHeader('x-goog-api-key')
                && ! str_contains($request->url(), 'key=');
        });
    }

    private function assertThinkingLevel(?string $expected): void
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

        Http::assertSent(function ($request) use ($expected) {
            $config = $request->data()['generationConfig'] ?? [];
            $actual = $config['thinkingConfig']['thinkingLevel'] ?? null;

            return $actual === $expected;
        });
    }

    public function test_thinking_level_is_sent_lowercase_by_default(): void
    {
        // A 3.x model outside the known lite families uses the generic level.
        config(['services.gemini.model' => 'gemini-3.9-flash', 'services.gemini.thinking_level' => 'low']);
        $this->assertThinkingLevel('low');
    }

    public function test_thinking_level_casing_is_configurable(): void
    {
        config(['services.gemini.model' => 'gemini-3.9-flash', 'services.gemini.thinking_level' => 'HIGH']);
        $this->assertThinkingLevel('HIGH');
    }

    public function test_empty_thinking_level_omits_thinking_config(): void
    {
        config(['services.gemini.model' => 'gemini-3.9-flash', 'services.gemini.thinking_level' => '']);
        $this->assertThinkingLevel(null);
    }

    public function test_thinking_level_matrix_by_model_family(): void
    {
        $cases = [
            'gemini-3.8-flash' => 'low',
            'gemini-3.5-flash-lite' => 'low',
            'gemini-3.1-flash-lite' => 'low',
            'gemini-2.5-flash' => null,
        ];

        foreach ($cases as $model => $expected) {
            config(['services.gemini.model' => $model, 'services.gemini.fallback_model' => $model]);
            app()->forgetInstance(GeminiClient::class);
            $this->assertThinkingLevel($expected);
        }
    }

    public function test_thinking_overrides_win_over_family_defaults(): void
    {
        config([
            'services.gemini.model' => 'gemini-3.8-flash',
            'services.gemini.fallback_model' => 'gemini-3.8-flash',
            'services.gemini.thinking_overrides' => 'gemini-3.8-flash=high,gemini-3.5-flash-lite=',
        ]);
        app()->forgetInstance(GeminiClient::class);
        $this->assertThinkingLevel('high');

        config(['services.gemini.model' => 'gemini-3.5-flash-lite', 'services.gemini.fallback_model' => 'gemini-3.5-flash-lite']);
        app()->forgetInstance(GeminiClient::class);
        $this->assertThinkingLevel(null);
    }

    public function test_non_gemini3_model_omits_thinking_config(): void
    {
        config(['services.gemini.model' => 'gemini-2.5-flash', 'services.gemini.thinking_level' => 'low']);
        $this->assertThinkingLevel(null);
    }

    public function test_per_model_timeout_defaults_and_overrides(): void
    {
        config(['services.gemini.timeout' => 50]);
        $client = new GeminiClient(app(GeminiCircuitBreaker::class));

        $this->assertSame(20, $client->timeoutForModel('gemini-3.5-flash-lite'));
        $this->assertSame(20, $client->timeoutForModel('gemini-3.1-flash-lite'));
        $this->assertSame(50, $client->timeoutForModel('gemini-3.8-flash'));

        config(['services.gemini.timeout_overrides' => 'gemini-3.8-flash=12,gemini-3.5-flash-lite=33']);
        $client = new GeminiClient(app(GeminiCircuitBreaker::class));
        $this->assertSame(12, $client->timeoutForModel('gemini-3.8-flash'));
        $this->assertSame(33, $client->timeoutForModel('gemini-3.5-flash-lite'));
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
            return Http::response(['error' => 'timeout'], 504);
        });

        $retries = [];
        app(GeminiClient::class)->analyzeWithFallback('img', 'prompt', function ($model, $number) use (&$retries) {
            $retries[] = $number;
        });

        // 504 server_error is same-model retryable once on primary, then fallback.
        $this->assertSame([1, 2], $retries);
    }
}
