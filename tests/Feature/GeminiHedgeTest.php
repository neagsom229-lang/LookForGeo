<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GeminiHedgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'AIzaSyFakeHedgeKey1234567890',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.fallback_model' => 'gemini-3.1-flash-lite',
            'services.gemini.retries' => 0,
            'services.gemini.max_total_time' => 10,
            'services.gemini.min_attempt_seconds' => 5,
            'services.gemini.hedge_after' => 1,
            // This suite counts Gemini HTTP calls; the missing-coordinate
            // Nominatim fallback is covered in MissingCoordinatesTest.
            'services.gemini.forward_geocode_missing' => false,
        ]);
    }

    private function valid(string $name): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'landmark_name' => $name,
                        'city' => 'Paris',
                        'country' => 'France',
                        'confidence' => 80,
                        'reasoning' => 'Tower',
                        'description' => 'Tower',
                    ]),
                ]]],
            ]],
        ];
    }

    public function test_fast_primary_does_not_start_the_fallback(): void
    {
        Http::fake(['*' => Http::response($this->valid('Primary'), 200)]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertArrayNotHasKey('hedged', $result);
        Http::assertSentCount(1);
    }

    public function test_slow_primary_loses_to_the_fallback(): void
    {
        Log::spy();
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;
            if (str_contains($request->url(), 'gemini-3.5-flash-lite')) {
                usleep(1200000); // slower than the 1s hedge window

                throw new ConnectionException('hedge timeout');
            }

            return Http::response($this->valid('Fallback'), 200);
        });

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertTrue($result['hedged']);
        $this->assertSame('gemini-3.1-flash-lite', $result['hedge_winner']);
        $this->assertSame('Fallback', $result['landmark_name']);
        $this->assertSame('gemini-3.5-flash-lite', $result['abandoned_primary']);
        $this->assertArrayHasKey('primary_abandoned_ms', $result);
        $this->assertSame(2, $attempts);
        Log::shouldHaveReceived('info')->with('Gemini primary timeout + fallback', Mockery::on(function ($context) {
            return ($context['hedged'] ?? false) === true
                && ($context['winner'] ?? null) === 'gemini-3.1-flash-lite'
                && isset($context['ms']);
        }));
    }

    public function test_both_hedged_models_fail_with_classified_error(): void
    {
        $attempts = 0;
        Http::fake(function ($request) use (&$attempts) {
            $attempts++;

            return Http::response(['error' => 'unavailable'], 503);
        });

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertSame('overload', $result['category']);
        $this->assertSame('The AI service is temporarily unavailable. Please retry shortly.', $result['user_message']);
        $this->assertSame(2, $attempts);
    }

    public function test_hedging_can_be_disabled(): void
    {
        config(['services.gemini.hedge_after' => 0]);
        Http::fake(['*' => Http::response($this->valid('Primary'), 200)]);

        app(GeminiService::class)->analyzeGeolocation('img', []);

        Http::assertSentCount(1);
    }
}
