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
    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $timeout = 180; // increased to allow for API retries

    protected $analysisId;
    protected $localPath;
    protected $filename;

    public function __construct($analysisId, $localPath = null, $filename = null)
    {
        $this->analysisId = $analysisId;
        $this->localPath = $localPath;
        $this->filename = $filename;

        Log::channel('single')->info('📦 AnalyzeImageJob created', [
            'analysis_id' => $analysisId,
            'local_path' => $localPath,
            'filename' => $filename,
        ]);
    }

    public function handle(GeminiService $geminiService)
    {
        try {
            Log::channel('single')->info('🔍 Starting AnalyzeImageJob for ID: ' . $this->analysisId);

            // ✅ Get analysis
            $analysis = GeoAnalysis::find($this->analysisId);

            if (!$analysis) {
                Log::channel('single')->error('❌ Analysis not found: ' . $this->analysisId);
                $this->fail(new \Exception('Analysis record not found'));
                return;
            }

            Log::channel('single')->info('📊 Analysis found', [
                'id' => $analysis->id,
                'status' => $analysis->status,
                'image_path' => $analysis->image_path,
                'image_url' => $analysis->image_url,
            ]);

            // ✅ Skip if already completed or permanently failed
            if (in_array($analysis->status, ['completed', 'failed'])) {
                Log::channel('single')->info('⏭️ Analysis already ' . $analysis->status . ', skipping: ' . $this->analysisId);
                return;
            }

            // ✅ STEP 1: Mark as processing
            Log::channel('single')->info('📝 Updating analysis to processing...');
            $analysis->update([
                'status' => 'processing',
                'stage' => 1,
                'stage_label' => 'Processing Image',
                'progress' => 20,
                'error' => null,
                'started_at' => now(),
            ]);

            // ✅ STEP 2: Load image data
            $imageData = $this->loadImageData($analysis);
            if (!$imageData) {
                $error = 'No image found. Path: ' . ($analysis->image_path ?? 'null') . ', URL: ' . ($analysis->image_url ?? 'null');
                Log::channel('single')->error('❌ ' . $error);
                $analysis->update([
                    'status' => 'failed',
                    'error' => $error,
                    'finished_at' => now(),
                ]);
                $this->fail(new \Exception($error));
                return;
            }

            Log::channel('single')->info('✅ Image loaded successfully, size: ' . strlen($imageData) . ' bytes');

            // ✅ STEP 3: Update to AI Analysis
            $analysis->update([
                'stage' => 2,
                'stage_label' => 'AI Analysis',
                'progress' => 40,
            ]);

            // ✅ STEP 4: Extract metadata
            $metadata = $this->extractMetadata($analysis);
            Log::channel('single')->info('✅ Metadata extracted', ['has_gps' => isset($metadata['gps'])]);

            // ✅ STEP 5: Call Gemini API (with retries handled inside service)
            Log::channel('single')->info('🤖 Calling Gemini API...');
            $aiResult = $geminiService->analyzeGeolocation($imageData, $metadata);

            // ✅ Check if the AI returned a real error (not just "Unknown Location")
            if (isset($aiResult['is_error']) && $aiResult['is_error'] === true) {
                $errorMsg = $aiResult['error_message'] ?? 'AI analysis failed without specific message';
                Log::channel('single')->error('❌ Gemini API returned error: ' . $errorMsg);
                throw new \Exception($errorMsg);
            }

            Log::channel('single')->info('✅ Gemini API returned result', [
                'landmark' => $aiResult['landmark_name'] ?? 'Unknown',
                'confidence' => $aiResult['confidence'] ?? 0,
            ]);

            // ✅ STEP 6: Update to Cloudinary
            $analysis->update([
                'stage' => 3,
                'stage_label' => 'Uploading to Cloudinary',
                'progress' => 70,
            ]);

            // ✅ STEP 7: Upload to Cloudinary
            $cloudinaryUrl = $this->uploadToCloudinary($analysis);
            Log::channel('single')->info('☁️ Cloudinary upload result', ['url' => $cloudinaryUrl ?? 'none']);

            // ✅ STEP 8: Complete
            $finalResult = array_merge($aiResult, [
                'image_url' => $cloudinaryUrl ?? $analysis->image_url,
            ]);

            $analysis->update([
                'status' => 'completed',
                'stage' => 4,
                'stage_label' => 'Complete',
                'progress' => 100,
                'result' => json_encode($finalResult),
                'image_url' => $cloudinaryUrl ?? $analysis->image_url,
                'finished_at' => now(),
                'error' => null,
            ]);

            Log::channel('single')->info('🎉 Analysis COMPLETED for ID: ' . $this->analysisId);

        } catch (\Exception $e) {
            Log::channel('single')->error('❌ Job FAILED: ' . $e->getMessage());
            Log::channel('single')->error('Stack trace: ' . $e->getTraceAsString());

            // Mark as failed if not already
            try {
                $analysis = GeoAnalysis::find($this->analysisId);
                if ($analysis && !in_array($analysis->status, ['completed', 'failed'])) {
                    $analysis->update([
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                        'finished_at' => now(),
                    ]);
                    Log::channel('single')->info('✅ Error status saved to database');
                }
            } catch (\Exception $updateError) {
                Log::channel('single')->error('❌ Failed to save error: ' . $updateError->getMessage());
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
            Log::channel('single')->info('📁 Loading from passed path: ' . $this->localPath);
            return file_get_contents($this->localPath);
        }

        // Check storage path (full path)
        if ($analysis->image_path) {
            $fullPath = storage_path('app/public/' . $analysis->image_path);
            if (file_exists($fullPath)) {
                Log::channel('single')->info('📁 Loading from storage path: ' . $fullPath);
                return file_get_contents($fullPath);
            }
        }

        // Check Laravel Storage
        if ($analysis->image_path && Storage::disk('public')->exists($analysis->image_path)) {
            Log::channel('single')->info('📁 Loading from Laravel Storage: ' . $analysis->image_path);
            return Storage::disk('public')->get($analysis->image_path);
        }

        // Check URL
        if ($analysis->image_url) {
            Log::channel('single')->info('📸 Loading from URL: ' . $analysis->image_url);
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
            Log::channel('single')->warning('⚠️ EXIF extraction failed: ' . $e->getMessage());
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
            Log::channel('single')->warning('☁️ No local file to upload to Cloudinary');
            return null;
        }

        // Load credentials from config (set in config/services.php)
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');

        if (empty($cloudName) || empty($apiKey) || empty($apiSecret)) {
            Log::channel('single')->warning('☁️ Cloudinary credentials not set, skipping upload');
            return null;
        }

        try {
            Log::channel('single')->info('☁️ Uploading to Cloudinary...');
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
            Log::channel('single')->info('✅ Cloudinary upload successful', ['url' => $url]);

            // Optionally delete local file after successful upload
            if ($url && file_exists($localFile)) {
                @unlink($localFile);
                Log::channel('single')->info('🗑️ Local file deleted after Cloudinary upload');
            }

            return $url;
        } catch (\Exception $e) {
            Log::channel('single')->error('❌ Cloudinary upload failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Called when all retries are exhausted.
     */
    public function failed(\Exception $exception)
    {
        Log::channel('single')->error('❌❌❌ Job PERMANENTLY FAILED after ' . $this->tries . ' attempts:', [
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
            Log::channel('single')->error('❌ Failed to update permanent failure: ' . $e->getMessage());
        }
    }
}