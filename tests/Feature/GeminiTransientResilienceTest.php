<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GeminiTransientResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.gemini.key' => 'AIzaSyFakeResilienceKey1234567890',
            'services.gemini.model' => 'gemini-primary',
            'services.gemini.fallback_model' => 'gemini-fallback',
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.timeout' => 5,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 1,
            'services.gemini.hedge_after' => 0,
            'services.gemini.circuit_failures' => 10,
        ]);
    }

    public function test_503_then_200_succeeds_on_fallback(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'gemini-primary')) {
                return Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'overloaded']], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Fallback Landmark',
                            'city' => 'Paris',
                            'country' => 'France',
                            'latitude' => 48.8,
                            'longitude' => 2.3,
                            'confidence' => 90,
                            'reasoning' => 'clear',
                            'description' => 'desc',
                        ]),
                    ]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], 200);
        });

        $result = app(GeminiService::class)->analyzeGeolocation('fake-image-bytes', []);

        $this->assertSame('Fallback Landmark', $result['landmark_name'] ?? null);
        $this->assertFalse((bool) ($result['is_error'] ?? false));
    }

    public function test_persistent_503_releases_job_with_delay_and_does_not_mark_failed(): void
    {
        Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE', 'message' => 'overloaded']], 503)]);
        Storage::fake('public');
        Storage::disk('public')->put('analyses/test.jpg', 'jpg');

        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/test.jpg',
            'image_url' => 'https://example.com/test.jpg',
            'started_at' => now(),
        ]);

        $job = new class($analysis->id) extends AnalyzeImageJob
        {
            public ?int $releasedDelay = null;

            public function release($delay = 0)
            {
                $this->releasedDelay = (int) $delay;
            }
        };
        $job->handle(app(GeminiService::class));

        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame('Waiting for AI service', $analysis->stage_label);
        $this->assertNull($analysis->finished_at);
        $this->assertSame(30, $job->releasedDelay);
    }

    public function test_429_gives_quota_message(): void
    {
        Http::fake(['*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota']], 429, ['Retry-After' => '7'])]);

        $result = app(GeminiService::class)->analyzeGeolocation('fake-image-bytes', []);

        $this->assertTrue((bool) ($result['is_error'] ?? false));
        $this->assertSame('quota', $result['category'] ?? null);
        $this->assertSame('Daily AI limit reached, try later.', $result['user_message'] ?? null);
    }
}
