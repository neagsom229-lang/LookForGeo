<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiEscalationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'AIzaSyFakeEscalateKey1234567890',
            'services.gemini.timeout' => 5,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 15,
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.escalation_model' => 'gemini-3.1-flash-lite',
            'services.gemini.escalate_below' => 60,
            'services.gemini.escalation_timeout' => 25,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => 'Unknown',
            'city' => 'Paris',
            'country' => 'France',
            'latitude' => 48.8584,
            'longitude' => 2.2945,
            'confidence' => 100,
            'reasoning' => 'Riveted iron tower near the Seine, 48.8584, 2.2945',
            'description' => 'A landmark',
            'visual_clues' => [],
            'candidates' => [],
        ], $overrides);
    }

    private function body(array $payload): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode($payload),
                ]]],
            ]],
        ];
    }

    public function test_escalation_improves_a_low_confidence_result(): void
    {
        $better = $this->payload([
            'landmark_name' => 'Eiffel Tower',
            'visual_clues' => ['iron lattice', 'rivets', 'seine', 'paris'],
            'candidates' => [
                ['location' => 'Eiffel Tower', 'probability' => 92, 'reasoning' => 'Tower'],
                ['location' => 'Arc', 'probability' => 20, 'reasoning' => 'Arch'],
            ],
        ]);

        Http::fakeSequence()
            ->push($this->body($this->payload()), 200)
            ->push($this->body($better), 200);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertTrue($result['escalated']);
        $this->assertSame('Eiffel Tower', $result['landmark_name']);
        Http::assertSentCount(2);
    }

    public function test_escalation_timeout_keeps_first_result_marked_low_confidence(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if (str_contains($request->url(), 'gemini-3.1-flash-lite')) {
                throw new ConnectionException('cURL 28 timeout');
            }

            return Http::response($this->body($this->payload()), 200);
        });

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertFalse($result['escalated'] ?? false);
        $this->assertTrue($result['low_confidence']);
        $this->assertTrue($result['escalation_failed']);
        $this->assertSame(2, $attempts, 'one first pass + one bounded escalation pass');
    }

    public function test_escalation_is_disabled_when_model_is_empty(): void
    {
        config(['services.gemini.escalation_model' => '']);
        Http::fake(['*' => Http::response($this->body($this->payload()), 200)]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertArrayNotHasKey('escalated', $result);
        $this->assertArrayNotHasKey('escalation_failed', $result);
        Http::assertSentCount(1);
    }
}
