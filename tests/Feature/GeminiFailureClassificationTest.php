<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiFailureClassificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'AIzaSyFakeClassifyKey1234567890',
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 15,
            'services.gemini.circuit_failures' => 6,
            'services.gemini.circuit_open_seconds' => 60,
        ]);
        app(GeminiCircuitBreaker::class)->reset();
    }

    public function test_five_categories_map_to_distinct_user_safe_messages_and_stage_labels(): void
    {
        $cases = [
            ['error' => 'quota_exceeded', 'http_status' => 429, 'category' => 'quota'],
            ['error' => 'service_unavailable', 'http_status' => 503, 'category' => 'overload'],
            ['error' => 'timeout', 'category' => 'timeout'],
            ['error' => 'api_error', 'http_status' => 400, 'category' => 'bad_request'],
            ['error' => 'server_error', 'http_status' => 500, 'category' => 'other'],
        ];

        $messages = [];
        foreach ($cases as $case) {
            $classified = GeminiFailureClassifier::classify($case);
            $this->assertSame($case['category'], $classified['category']);
            $this->assertTrue(GeminiFailureClassifier::isSafeUserMessage($classified['user_message']));
            $this->assertStringStartsWith('Waiting for AI service', $classified['stage_label']);
            $messages[] = $classified['user_message'];
        }

        $this->assertCount(5, array_unique($messages), 'each category needs a distinct message');
    }

    public function test_429_is_not_retried_on_the_same_model_and_retry_after_is_carried(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-3.6-flash')) {
                return Http::response(['error' => 'quota'], 429, ['Retry-After' => '7']);
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
        $primaryCalls = 0;
        Http::assertSent(function ($request) use (&$primaryCalls) {
            if (str_contains($request->url(), 'gemini-3.6-flash')) {
                $primaryCalls++;
            }

            return true;
        });
        $this->assertSame(1, $primaryCalls, '429 must never be retried on the same model');
    }

    public function test_429_retry_after_is_exposed_in_the_classified_result(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429, ['Retry-After' => '7'])]);

        $result = app(GeminiClient::class)->analyzeWithFallback('img', 'prompt');

        $this->assertSame('quota', $result['category']);
        $this->assertSame(7, $result['retry_after']);
        $this->assertSame(GeminiFailureClassifier::MESSAGES['quota'], $result['user_message']);
    }

    public function test_circuit_breaker_fails_fast_with_overload_message(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);
        $client = app(GeminiClient::class);

        // Three analyses x (primary + fallback) = six failures = threshold.
        for ($i = 0; $i < 3; $i++) {
            $client->analyzeWithFallback('img', 'prompt');
        }

        $breaker = app(GeminiCircuitBreaker::class);
        $this->assertTrue($breaker->isOpen());
        Http::assertSentCount(6);

        $result = $client->analyzeWithFallback('img', 'prompt');

        $this->assertSame('circuit_open', $result['category']);
        $this->assertSame(GeminiFailureClassifier::MESSAGES['overload'], $result['user_message']);
        $this->assertGreaterThan(0, $result['retry_after_seconds']);
        // No further HTTP request while the breaker is open.
        Http::assertSentCount(6);
    }
}
