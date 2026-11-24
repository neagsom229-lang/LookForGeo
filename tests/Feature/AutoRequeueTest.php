<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutoRequeueTest extends TestCase
{
    use RefreshDatabase;

    private function analysis(): GeoAnalysis
    {
        return GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'processing',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service',
            'progress' => 40,
            'image_url' => 'https://example.com/test.jpg',
            'started_at' => now()->subSeconds(5),
        ]);
    }

    public function test_transient_overload_releases_job_with_delay_and_keeps_processing(): void
    {
        Http::fake(['*' => Http::response(['error' => ['status' => 'UNAVAILABLE']], 503)]);

        config([
            'services.gemini.key' => 'AIzaSyFakeKey1234567890',
            'services.gemini.fallback_model' => '',
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 1,
            'services.gemini.hedge_after' => 0,
        ]);

        $analysis = $this->analysis();
        $job = new class($analysis->id) extends AnalyzeImageJob {
            public ?int $releasedDelay = null;
            public function release($delay = 0) { $this->releasedDelay = (int) $delay; }
        };
        $job->handle(app(\App\Services\GeminiService::class));

        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame('Waiting for AI service', $analysis->stage_label);
        $this->assertNull($analysis->finished_at);
        $this->assertNotNull($job->releasedDelay);
    }

    public function test_bad_request_category_is_not_requeued(): void
    {
        Http::fake(['*' => Http::response(['error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'bad']], 400)]);

        config([
            'services.gemini.key' => 'AIzaSyFakeKey1234567890',
            'services.gemini.fallback_model' => '',
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 1,
            'services.gemini.hedge_after' => 0,
        ]);

        $analysis = $this->analysis();
        $job = new AnalyzeImageJob($analysis->id);
        $job->handle(app(\App\Services\GeminiService::class));

        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
    }
}
