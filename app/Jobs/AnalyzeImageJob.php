<?php

namespace App\Jobs;

use App\Models\GeoAnalysis;
use App\Services\Gemini\GeminiFailureClassifier;
use App\Services\Gemini\SecretSanitizer;
use App\Services\GeminiService;
use Cloudinary\Configuration\Configuration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AnalyzeImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // ONE retry layer lives in GeminiClient (one retry on primary, then one
    // fallback model, all within a 90s budget). The queue itself does not
    // retry: release(45) and a high $tries only burned worker attempts on
    // jobs whose handle() already marks the analysis failed. A stuck job is
    // recovered by `analyses:reap-stuck` instead.
    public int $tries = 1;

    public int $timeout = 180;

    protected int $analysisId;

    protected ?string $localPath;

    protected ?string $filename;

    public function __construct(int $analysisId, ?string $localPath = null, ?string $filename = null)
    {
        $this->analysisId = $analysisId;
        $this->localPath = $localPath;
        $this->filename = $filename;

        Log::info('📦 AnalyzeImageJob created', [
            'analysis_id' => $analysisId,
            'local_path' => $localPath,
            'filename' => $filename,
        ]);
    }

    /**
     * Telemetry is best-effort: never fail an analysis because a column is
     * missing (e.g. deploy order or a cached schema before migration).
     */
    private function writeTelemetry(GeoAnalysis $analysis, array $values): void
    {
        try {
            $columns = Cache::remember('analysis_telemetry_columns', 3600, function () {
                $available = [];
                foreach (['model_used', 'processing_ms', 'queue_ms', 'gemini_ms', 'total_ms', 'failure_category'] as $column) {
                    $available[$column] = Schema::hasColumn('geo_analyses', $column);
                }

                return $available;
            });

            $safe = array_intersect_key($values, array_filter($columns));
            if ($safe !== []) {
                $analysis->update($safe);
            }
        } catch (\Throwable $e) {
            Log::warning('Telemetry write skipped: '.SecretSanitizer::redact($e->getMessage()));
        }
    }

    public function handle(GeminiService $geminiService)
    {
        Log::info('🔥 AnalyzeImageJob STARTED for ID '.$this->analysisId);

        try {
            // Get analysis
            $analysis = GeoAnalysis::find($this->analysisId);
            if (! $analysis) {
                Log::error('❌ Analysis not found: '.$this->analysisId);
                $this->fail(new \Exception('Analysis record not found'));

                return;
            }

            Log::info('📊 Analysis found', [
                'id' => $analysis->id,
                'status' => $analysis->status,
                'image_path' => $analysis->image_path,
            ]);

            // Skip if already completed or failed
            if (in_array($analysis->status, ['completed', 'failed'])) {
                Log::info('⏭️ Analysis already '.$analysis->status.', skipping: '.$this->analysisId);

                return;
            }

            // STEP 1: Mark as processing
            $analysis->markStage(1, 20);
            Log::info('📝 Updated to stage 1 (processing)');

            // STEP 2: Load image data
            $imageData = $this->loadImageData($analysis);
            if (! $imageData) {
                $error = 'No image found. Path: '.($analysis->image_path ?? 'null');
                Log::error('❌ '.$error);
                $analysis->markAsFailed(SecretSanitizer::redact($error));
                $this->writeTelemetry($analysis, ['failure_category' => 'other']);
                $this->fail(new \Exception($error));

                return;
            }
            Log::info('✅ Image loaded, size: '.strlen($imageData).' bytes');

            // STEP 3: Extract metadata
            $metadata = $this->extractMetadata($analysis);
            // Resolution is a checkable confidence signal. The model never
            // sees this; it only feeds the calibrated score.
            $size = @getimagesizefromstring($imageData);
            if (is_array($size)) {
                $metadata['resolution'] = ['width' => $size[0], 'height' => $size[1]];
            }
            Log::info('✅ Metadata extracted', ['has_gps' => isset($metadata['gps'])]);

            // STEP 4: AI Analysis
            $analysis->markStage(2, 40);
            $analysis->update(['stage_label' => 'Waiting for AI service']);
            Log::info('🤖 Calling Gemini API...');

            // Surface each internal retry in the UI via a stage label. The
            // callback runs on the primary retry (n=1) and before the
            // fallback model (n=2), so a user watching the progress card
            // sees "Waiting for AI service, retry 1/2".
            $aiResult = $geminiService->analyzeGeolocation(
                $imageData,
                $metadata,
                function (string $model, int $retryNumber) use ($analysis) {
                    $analysis->update([
                        'stage_label' => 'Waiting for AI service, retry '.$retryNumber,
                    ]);
                    Log::warning('Gemini retry scheduled', [
                        'analysis_id' => $analysis->id,
                        'model' => $model,
                        'retry' => $retryNumber,
                    ]);
                }
            );

            // Transient errors are retried INSIDE GeminiClient (one retry on
            // the primary, then one fallback). There is no release(45) here:
            // a failed AI result is terminal, shown to the user with a Retry
            // button. Queue-level retries were no-ops and delayed visibility.

            // Check for other AI errors (including is_error flag)
            if (isset($aiResult['is_error']) && $aiResult['is_error'] === true) {
                $errorMsg = $aiResult['error_message'] ?? 'AI analysis failed without specific message';
                Log::error('❌ Gemini API returned error: '.SecretSanitizer::redact($errorMsg));

                // Store only the classified generic message and stage label;
                // raw provider details stay in the (sanitized) log.
                $analysis->update([
                    'stage_label' => $aiResult['stage_label'] ?? GeminiFailureClassifier::STAGE_LABELS['other'],
                ]);
                throw new \Exception($aiResult['user_message'] ?? GeminiFailureClassifier::DEFAULT_USER_MESSAGE);
            }

            // Also check if the result is an array with an 'error' key (non-503)
            if (isset($aiResult['error'])) {
                $errorMsg = $aiResult['message'] ?? 'Unknown AI error';
                Log::error('❌ Gemini API returned error: '.$errorMsg);
                throw new \Exception($errorMsg);
            }

            Log::info('✅ Gemini API returned result', [
                'landmark' => $aiResult['landmark_name'] ?? 'Unknown',
                'confidence' => $aiResult['confidence'] ?? 0,
            ]);

            // STEP 5: Complete immediately with the existing local/CDN URL.
            // Cloudinary upload moves to a follow-up job so a slow or failed
            // upload can never delay or fail the user's result.
            $analysis->markStage(3, 70);
            $finalResult = array_merge($aiResult, [
                'image_url' => $analysis->image_url,
            ]);

            $analysis->markAsCompleted($finalResult);
            $finishedAt = $analysis->finished_at ?? now();
            $this->writeTelemetry($analysis, [
                'model_used' => $aiResult['hedge_winner'] ?? (string) config('services.gemini.model'),
                'processing_ms' => $analysis->started_at
                    ? (int) abs($analysis->started_at->diffInMilliseconds($finishedAt))
                    : null,
                'queue_ms' => ($analysis->created_at && $analysis->started_at)
                    ? (int) abs($analysis->created_at->diffInMilliseconds($analysis->started_at))
                    : null,
                'gemini_ms' => $analysis->started_at
                    ? (int) abs($analysis->started_at->diffInMilliseconds($finishedAt))
                    : null,
                'total_ms' => $analysis->created_at
                    ? (int) abs($analysis->created_at->diffInMilliseconds($finishedAt))
                    : null,
            ]);
            UploadAnalysisImageJob::dispatch($analysis->id);
            Log::info('🎉 Analysis COMPLETED for ID: '.$this->analysisId);

        } catch (\Throwable $e) {
            // Details go to the log (sanitized); the DB error column gets
            // the same sanitized text -- key material can never be stored.
            Log::error('❌ Job FAILED: '.SecretSanitizer::redact($e->getMessage()));
            Log::error('Stack trace: '.SecretSanitizer::redact($e->getTraceAsString()));

            // Hard (non-transient) failure — mark failed and return so the
            // worker does not retry: retries are no-ops anyway once status
            // is 'failed' (handle() skips completed/failed analyses), so
            // throwing would just burn the remaining attempts.
            try {
                $analysis = GeoAnalysis::find($this->analysisId);
                if ($analysis && ! in_array($analysis->status, ['completed', 'failed'])) {
                    $analysis->markAsFailed(SecretSanitizer::redact($e->getMessage()));
                    $this->writeTelemetry($analysis, [
                        'model_used' => (string) config('services.gemini.model'),
                        'failure_category' => GeminiFailureClassifier::categoryFromStageLabel($analysis->stage_label) ?? 'other',
                    ]);
                    Log::info('✅ Error status saved to database');
                }
            } catch (\Throwable $updateError) {
                Log::error('❌ Failed to save error: '.SecretSanitizer::redact($updateError->getMessage()));
            }

            return;
        }
    }

    /**
     * Load image data from various sources.
     */
    private function loadImageData(GeoAnalysis $analysis)
    {
        // Local runs: always read the stored file from disk first. HTTP
        // self-fetch is only for workers with no shared filesystem.
        $localFile = $this->getAbsolutePath($analysis);
        if ($localFile && is_file($localFile)) {
            Log::info('Loading image from local disk: '.$localFile);

            return file_get_contents($localFile);
        }

        foreach (array_filter([$this->localPath, $analysis->image_path, $analysis->image_url]) as $url) {
            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }
            Log::info('Fetching image from URL: '.$url);
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'TraceGeo/1.0 (https://lookforgeo.onrender.com)'])
                    ->get($url);
                if ($response->successful()) {
                    return $response->body();
                }
                Log::error('HTTP '.$response->status().' fetching image');
            } catch (\Throwable $e) {
                Log::error('HTTP fetch failed: '.$e->getMessage());
            }
        }

        Log::error('No image source available');

        return null;
    }

    /**
     * Gets the absolute path reliably across both the web server and queue worker.
     */
    private function getAbsolutePath(GeoAnalysis $analysis)
    {
        if ($this->localPath && file_exists($this->localPath)) {
            return $this->localPath;
        }
        if ($analysis->image_path) {
            $disk = Storage::disk('public');
            if ($disk->exists($analysis->image_path)) {
                return $disk->path($analysis->image_path);
            }
        }

        return null;
    }

    /**
     * Extract EXIF metadata from the image.
     */
    private function extractMetadata(GeoAnalysis $analysis)
    {
        $data = [];
        $filePath = null;

        $filePath = $this->getAbsolutePath($analysis);

        if (! $filePath) {
            return $data;
        }

        if (! function_exists('exif_read_data')) {
            return $data;
        }

        try {
            $exif = @exif_read_data($filePath);
            if ($exif) {
                if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                    $data['gps'] = [
                        'latitude' => $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N'),
                        'longitude' => $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E'),
                    ];
                }
                $data['camera'] = [
                    'make' => $exif['Make'] ?? null,
                    'model' => $exif['Model'] ?? null,
                ];
                $data['datetime'] = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                if (isset($exif['ISOSpeedRatings'])) {
                    $data['settings']['iso'] = $exif['ISOSpeedRatings'];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('⚠️ EXIF extraction failed: '.$e->getMessage());
        }

        return $data;
    }

    private function gpsToDecimal($gps, $ref)
    {
        $degrees = $gps[0] ?? 0;
        $minutes = $gps[1] ?? 0;
        $seconds = $gps[2] ?? 0;

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array($ref, ['S', 'W'])) {
            $decimal = -$decimal;
        }

        return $decimal;
    }

    /**
     * Upload to Cloudinary using environment configuration.
     * Returns the secure URL or null on failure.
     */
    public function failed(\Throwable $exception)
    {
        Log::error('❌❌❌ Job PERMANENTLY FAILED after '.$this->tries.' attempts:', [
            'analysis_id' => $this->analysisId,
            'error' => SecretSanitizer::redact($exception->getMessage()),
        ]);

        try {
            $analysis = GeoAnalysis::find($this->analysisId);
            if ($analysis && ! in_array($analysis->status, ['completed', 'failed'])) {
                $analysis->update([
                    'status' => 'failed',
                    'error' => SecretSanitizer::redact('Permanent failure: '.$exception->getMessage()),
                    'finished_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('❌ Failed to update permanent failure: '.SecretSanitizer::redact($e->getMessage()));
        }
    }
}
