<?php

namespace App\Jobs;

use App\Models\GeoAnalysis;
use App\Services\GeminiService;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnalyzeImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Retry configuration – 3 attempts with exponential backoff
    public int $tries = 5;
    public array $backoff = [10, 30, 60];
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
        Log::info('🔥 AnalyzeImageJob STARTED for ID ' . $this->analysisId);

        try {
            // Get analysis
            $analysis = GeoAnalysis::find($this->analysisId);
            if (!$analysis) {
                Log::error('❌ Analysis not found: ' . $this->analysisId);
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
                Log::info('⏭️ Analysis already ' . $analysis->status . ', skipping: ' . $this->analysisId);
                return;
            }

            // STEP 1: Mark as processing
            $analysis->markStage(1, 20);
            Log::info('📝 Updated to stage 1 (processing)');

            // STEP 2: Load image data
            $imageData = $this->loadImageData($analysis);
            if (!$imageData) {
                $error = 'No image found. Path: ' . ($analysis->image_path ?? 'null');
                Log::error('❌ ' . $error);
                $analysis->markAsFailed($error);
                $this->fail(new \Exception($error));
                return;
            }
            Log::info('✅ Image loaded, size: ' . strlen($imageData) . ' bytes');

            // STEP 3: Extract metadata
            $metadata = $this->extractMetadata($analysis);
            Log::info('✅ Metadata extracted', ['has_gps' => isset($metadata['gps'])]);

            // STEP 4: AI Analysis
            $analysis->markStage(2, 40);
            Log::info('🤖 Calling Gemini API...');

            $aiResult = $geminiService->analyzeGeolocation($imageData, $metadata);

            // Check for 503 (service unavailable) – release job for retry
            if (isset($aiResult['error']) && str_contains($aiResult['message'] ?? '', '503')) {
                Log::warning('⚠️ Gemini service unavailable (503), releasing job for retry...');
                $this->release(30);
                return;
            }

            // Check for other AI errors (including is_error flag)
            if (isset($aiResult['is_error']) && $aiResult['is_error'] === true) {
                $errorMsg = $aiResult['error_message'] ?? 'AI analysis failed without specific message';
                Log::error('❌ Gemini API returned error: ' . $errorMsg);
                throw new \Exception($errorMsg);
            }

            // Also check if the result is an array with an 'error' key (non-503)
            if (isset($aiResult['error'])) {
                $errorMsg = $aiResult['message'] ?? 'Unknown AI error';
                Log::error('❌ Gemini API returned error: ' . $errorMsg);
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
            Log::info('🎉 Analysis COMPLETED for ID: ' . $this->analysisId);

        } catch (\Exception $e) {
            Log::error('❌ Job FAILED: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            // Update status to failed if not already
            try {
                $analysis = GeoAnalysis::find($this->analysisId);
                if ($analysis && !in_array($analysis->status, ['completed', 'failed'])) {
                    $analysis->markAsFailed($e->getMessage());
                    Log::info('✅ Error status saved to database');
                }
            } catch (\Exception $updateError) {
                Log::error('❌ Failed to save error: ' . $updateError->getMessage());
            }

            // Re-throw to trigger job retry (if attempts remain)
            throw $e;
        }
    }

    /**
     * Load image data from various sources.
     */
    private function loadImageData(GeoAnalysis $analysis)
    {
        // Check passed path
        if ($this->localPath && file_exists($this->localPath)) {
            Log::info('📁 Loading from passed path: ' . $this->localPath);
            return file_get_contents($this->localPath);
        }

        // Check storage path (full path)
        if ($analysis->image_path) {
            $fullPath = storage_path('app/public/' . $analysis->image_path);
            if (file_exists($fullPath)) {
                Log::info('📁 Loading from storage path: ' . $fullPath);
                return file_get_contents($fullPath);
            }
        }

        // Check Laravel Storage
        if ($analysis->image_path && Storage::disk('public')->exists($analysis->image_path)) {
            Log::info('📁 Loading from Laravel Storage: ' . $analysis->image_path);
            return Storage::disk('public')->get($analysis->image_path);
        }

        // Check URL
        if ($analysis->image_url) {
            Log::info('📸 Loading from URL: ' . $analysis->image_url);
            $data = @file_get_contents($analysis->image_url);
            if ($data) {
                return $data;
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

        if ($this->localPath && file_exists($this->localPath)) {
            $filePath = $this->localPath;
        } elseif ($analysis->image_path) {
            $fullPath = storage_path('app/public/' . $analysis->image_path);
            if (file_exists($fullPath)) {
                $filePath = $fullPath;
            }
        }

        if (!$filePath) {
            return $data;
        }

        if (!function_exists('exif_read_data')) {
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
        } catch (\Exception $e) {
            Log::warning('⚠️ EXIF extraction failed: ' . $e->getMessage());
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
        $localFile = null;

        if ($this->localPath && file_exists($this->localPath)) {
            $localFile = $this->localPath;
        } elseif ($analysis->image_path) {
            $fullPath = storage_path('app/public/' . $analysis->image_path);
            if (file_exists($fullPath)) {
                $localFile = $fullPath;
            }
        }

        if (!$localFile) {
            Log::warning('☁️ No local file to upload to Cloudinary');
            return null;
        }

        // Load credentials from config
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

            $uploadApi = new UploadApi();
            $publicId = pathinfo($this->filename ?? $analysis->image_path ?? 'image', PATHINFO_FILENAME);
            $uploadResult = $uploadApi->upload($localFile, [
                'folder' => 'tracegeo/analyses',
                'public_id' => $publicId,
            ]);

            $url = $uploadResult['secure_url'] ?? null;
            Log::info('✅ Cloudinary upload successful', ['url' => $url]);

            // Optionally delete local file after successful upload
            if ($url && file_exists($localFile)) {
                @unlink($localFile);
                Log::info('🗑️ Local file deleted after Cloudinary upload');
            }

            return $url;
        } catch (\Exception $e) {
            Log::error('❌ Cloudinary upload failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Called when all retries are exhausted.
     */
    public function failed(\Exception $exception)
    {
        Log::error('❌❌❌ Job PERMANENTLY FAILED after ' . $this->tries . ' attempts:', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $analysis = GeoAnalysis::find($this->analysisId);
            if ($analysis && !in_array($analysis->status, ['completed', 'failed'])) {
                $analysis->update([
                    'status' => 'failed',
                    'error' => 'Permanent failure: ' . $exception->getMessage(),
                    'finished_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('❌ Failed to update permanent failure: ' . $e->getMessage());
        }
    }
}