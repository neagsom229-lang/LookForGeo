<?php

namespace App\Jobs;

use App\Models\GeoAnalysis;
use App\Services\Gemini\GeminiFailureClassifier;
use App\Services\Gemini\SecretSanitizer;
use App\Services\GeminiService;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

            // STEP 5: Upload to Cloudinary (optional, continues even if fails)
            $analysis->markStage(3, 70);
            $cloudinaryUrl = $this->uploadToCloudinary($analysis);
            Log::info('☁️ Cloudinary upload result', ['url' => $cloudinaryUrl ?? 'none']);

            // STEP 6: Complete – ensure image_url is set
            $finalResult = array_merge($aiResult, [
                'image_url' => $cloudinaryUrl ?? $analysis->image_url,
            ]);

            $analysis->markAsCompleted($finalResult);
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
        // 1. If localPath is a URL (Cloudinary, post-fix), fetch it via HTTP
        if ($this->localPath && filter_var($this->localPath, FILTER_VALIDATE_URL)) {
            Log::info('🌐 Fetching from URL: '.$this->localPath);
            try {
                $response = Http::timeout(30)
                    ->withHeaders([
                        'User-Agent' => 'TraceGeo/1.0 (https://lookforgeo.onrender.com)',
                    ])
                    ->get($this->localPath);
                if ($response->successful()) {
                    return $response->body();
                }
                Log::error('❌ HTTP '.$response->status().' fetching image');
            } catch (\Throwable $e) {
                Log::error('❌ HTTP fetch failed: '.$e->getMessage());
            }
        }

        // 2. If image_path is a URL (from DB), fetch it
        if ($analysis->image_path && filter_var($analysis->image_path, FILTER_VALIDATE_URL)) {
            Log::info('🌐 Fetching from DB image_path: '.$analysis->image_path);
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'TraceGeo/1.0'])
                    ->get($analysis->image_path);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::error('❌ DB URL fetch failed: '.$e->getMessage());
            }
        }

        // 3. If image_url is set (Cloudinary), fetch it
        if ($analysis->image_url && filter_var($analysis->image_url, FILTER_VALIDATE_URL)) {
            Log::info('🌐 Fetching from image_url: '.$analysis->image_url);
            try {
                $response = Http::timeout(30)
                    ->withHeaders(['User-Agent' => 'TraceGeo/1.0'])
                    ->get($analysis->image_url);
                if ($response->successful()) {
                    return $response->body();
                }
            } catch (\Throwable $e) {
                Log::error('❌ image_url fetch failed: '.$e->getMessage());
            }
        }

        // 4. Fallback: local filesystem (dev only)
        if ($this->localPath && file_exists($this->localPath)) {
            Log::info('📁 Loading from local path: '.$this->localPath);

            return file_get_contents($this->localPath);
        }

        if ($analysis->image_path) {
            $disk = Storage::disk('public');
            if ($disk->exists($analysis->image_path)) {
                $fullPath = $disk->path($analysis->image_path);
                Log::info('📁 Loading from Storage Disk: '.$fullPath);

                return file_get_contents($fullPath);
            }
        }

        Log::error('❌ No image source available');

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
    private function uploadToCloudinary(GeoAnalysis $analysis)
    {
        // If already on Cloudinary (post-fix flow), just return the URL
        if ($analysis->image_url && str_contains($analysis->image_url, 'cloudinary.com')) {
            Log::info('☁️ Already on Cloudinary, skipping re-upload');

            return $analysis->image_url;
        }

        // Legacy: try to upload from local (dev only)
        $localFile = $this->getAbsolutePath($analysis);
        if (! $localFile) {
            Log::warning('☁️ No local file to upload to Cloudinary');

            return $analysis->image_url ?? null;
        }

        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            Log::warning('☁️ Cloudinary credentials not set, skipping upload');

            return null;
        }

        try {
            Log::info('☁️ Uploading to Cloudinary...');
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => $cloudName,
                    'api_key' => $apiKey,
                    'api_secret' => $apiSecret,
                ],
                'url' => ['secure' => true],
            ]);

            $uploadApi = new UploadApi;
            $publicId = pathinfo($this->filename ?? $analysis->image_path ?? 'image', PATHINFO_FILENAME);
            $uploadResult = $uploadApi->upload($localFile, [
                'folder' => 'tracegeo/analyses',
                'public_id' => $publicId,
            ]);

            $url = $uploadResult['secure_url'] ?? null;
            Log::info('✅ Cloudinary upload successful', ['url' => $url]);

            return $url;
        } catch (\Throwable $e) {
            Log::error('❌ Cloudinary upload failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Called when all retries are exhausted.
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
