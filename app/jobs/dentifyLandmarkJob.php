<?php

namespace App\Jobs;

use App\Services\LandmarkRecognitionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs LandmarkRecognitionService::identify() in the background and writes
 * status/result to a cache key scoped to this ONE analysis (see
 * GeoAnalysisController) — never a shared/global key, so concurrent users
 * (or a second upload from the same user) can't collide with each other.
 */
class IdentifyLandmarkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // LandmarkRecognitionService already retries internally on transient errors

    public int $timeout = 120; // must exceed the service's own 45s HTTP timeout + retry backoff

    public function __construct(
        private readonly string $analysisId,
        private readonly string $imagePath,
        private readonly string $mode,
        private readonly array $hints = [],
    ) {}

    public function handle(LandmarkRecognitionService $service): void
    {
        $statusKey = "analysis_status_{$this->analysisId}";
        $resultKey = "analysis_result_{$this->analysisId}";

        try {
            $result = $service->identify($this->imagePath, $this->mode, $this->hints);

            Cache::put($resultKey, $result, now()->addMinutes(30));
            Cache::put($statusKey, empty($result['error_code']) ? 'complete' : 'failed', now()->addMinutes(30));
        } catch (\Throwable $e) {
            Log::error('IdentifyLandmarkJob failed: ' . $e->getMessage());

            Cache::put($resultKey, [
                'landmark_name' => null,
                'confidence' => 0,
                'description' => 'Analysis failed unexpectedly. Please try again.',
                'coordinate_source' => null,
                'error_code' => 'job_exception',
            ], now()->addMinutes(30));
            Cache::put($statusKey, 'failed', now()->addMinutes(30));
        }
    }

    public function failed(\Throwable $e): void
    {
        // Covers the case where the job itself couldn't even start/run
        // (e.g. queue worker crashed) — without this, a client polling for
        // this analysis id would wait forever with no 'failed' status ever set.
        Cache::put("analysis_status_{$this->analysisId}", 'failed', now()->addMinutes(30));
        Cache::put("analysis_result_{$this->analysisId}", [
            'landmark_name' => null,
            'confidence' => 0,
            'description' => 'Analysis could not be completed.',
            'coordinate_source' => null,
            'error_code' => 'job_failed',
        ], now()->addMinutes(30));
    }
}