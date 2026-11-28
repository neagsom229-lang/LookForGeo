<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_openai_compatible_fallback_used_when_gemini_fails(): void
    {
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.fallback_model' => 'gemini-test',
            'services.gemini.hedge_after' => 0,
            'services.ai_fallback.enabled' => true,
            'services.ai_fallback.key' => 'test-fallback-key',
            'services.ai_fallback.base_url' => 'https://fallback.example/v1',
            'services.ai_fallback.model' => 'free-vision-model',
            'services.ai_fallback.timeout' => 5,
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => false,
            'services.gemini.forward_geocode_missing' => false,
        ]);

        $json = json_encode([
            'landmark_name' => 'Test Landmark',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'region' => 'Phnom Penh',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 70,
            'reasoning' => 'Visual clues match.',
            'negative_evidence' => 'No other match.',
            'vantage_point' => 'ground',
            'visual_clues' => ['a', 'b', 'c', 'd', 'e'],
            'tags' => ['a', 'b', 'c', 'd', 'e'],
            'description' => 'desc',
            'type' => 'landmark',
            'nearby_landmarks' => [],
            'wikipedia_url' => null,
            'nearest_airport' => null,
            'candidates' => [[
                'location' => 'Test Landmark',
                'city' => 'Phnom Penh',
                'country' => 'Cambodia',
                'latitude' => 11.5564,
                'longitude' => 104.9282,
                'probability' => 70,
                'reasoning' => 'match',
            ]],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
            'https://fallback.example/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        $svc = app(GeminiService::class);
        $result = $svc->analyzeGeolocation('fake-image-bytes', []);

        $this->assertSame('Test Landmark', $result['landmark_name'] ?? null);
        $this->assertSame('free-vision-model', $result['winner'] ?? null);
        $this->assertSame('openai_compatible', $result['fallback_provider'] ?? null);
    }

    public function test_openrouter_headers_sent_when_configured(): void
    {
        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.gemini.model' => 'gemini-test',
            'services.gemini.fallback_model' => 'gemini-test',
            'services.gemini.hedge_after' => 0,
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => false,
            'services.gemini.forward_geocode_missing' => false,
            'services.ai_fallback.enabled' => true,
            'services.ai_fallback.key' => 'test-fallback-key',
            'services.ai_fallback.base_url' => 'https://openrouter.ai/api/v1',
            'services.ai_fallback.model' => 'nvidia/nemotron-3.5-super-vl:free',
            'services.ai_fallback.timeout' => 5,
            'services.ai_fallback.http_referer' => 'https://lookforgeo.test',
            'services.ai_fallback.app_title' => 'LookForGeo',
        ]);

        $json = json_encode([
            'landmark_name' => 'Test Landmark', 'city' => 'Phnom Penh', 'country' => 'Cambodia',
            'region' => 'Phnom Penh', 'latitude' => 11.5564, 'longitude' => 104.9282,
            'confidence' => 70, 'reasoning' => 'Visual clues match.', 'negative_evidence' => 'No other match.',
            'vantage_point' => 'ground', 'visual_clues' => ['a', 'b', 'c', 'd', 'e'], 'tags' => ['a', 'b', 'c', 'd', 'e'],
            'description' => 'desc', 'type' => 'landmark', 'nearby_landmarks' => [],
            'wikipedia_url' => null, 'nearest_airport' => null,
            'candidates' => [['location' => 'Test Landmark', 'city' => 'Phnom Penh', 'country' => 'Cambodia', 'latitude' => 11.5564, 'longitude' => 104.9282, 'probability' => 70, 'reasoning' => 'match']],
        ]);

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503),
            'https://openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => $json]]],
            ], 200),
        ]);

        $svc = app(GeminiService::class);
        $result = $svc->analyzeGeolocation('fake-image-bytes', []);

        $this->assertSame('nvidia/nemotron-3.5-super-vl:free', $result['winner'] ?? null);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request->hasHeader('HTTP-Referer', 'https://lookforgeo.test')
                && $request->hasHeader('X-OpenRouter-Title', 'LookForGeo');
        });
    }
}
