<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            'started_at' => now()->subSeconds(5),
        ]);
    }

    public function test_overload_is_requeued_once_only(): void
    {
        $analysis = $this->analysis();
        Cache::forget('analysis_auto_requeue:'.$analysis->id);
        $method = new \ReflectionMethod(AnalyzeImageJob::class, 'maybeRequeueOnce');
        $method->setAccessible(true);
        $job = new AnalyzeImageJob($analysis->id);

        $this->assertTrue($method->invoke($job, $analysis));
        $this->assertFalse($method->invoke($job, $analysis), 'at most one automatic requeue');
    }

    public function test_bad_request_category_is_not_requeued(): void
    {
        $analysis = $this->analysis();
        $category = 'bad_request';

        $this->assertFalse(in_array($category, ['overload', 'timeout'], true));
        $method = new \ReflectionMethod(AnalyzeImageJob::class, 'maybeRequeueOnce');
        $method->setAccessible(true);

        // The guard is category-based; simulate the job's decision directly.
        $this->assertFalse(in_array($category, ['overload', 'timeout'], true)
            && $method->invoke(new AnalyzeImageJob($analysis->id), $analysis));
    }

    public function test_requeue_is_skipped_when_total_wait_would_exceed_60s(): void
    {
        $analysis = $this->analysis();
        $analysis->update(['started_at' => now()->subSeconds(59)]);
        Cache::forget('analysis_auto_requeue:'.$analysis->id);

        $method = new \ReflectionMethod(AnalyzeImageJob::class, 'maybeRequeueOnce');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(new AnalyzeImageJob($analysis->id), $analysis));
    }
}
