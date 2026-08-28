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

    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $timeout = 120;

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

            // ✅ Skip if already completed
            if ($analysis->status === 'completed') {
                Log::channel('single')->info('⏭️ Analysis already completed, skipping: ' . $this->analysisId);
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
            ]);
            Log::channel('single')->info('✅ Updated to stage 1');

            // ✅ STEP 2: Find and load image
            Log::channel('single')->info('🔍 Looking for image file...');
            $imageData = null;
            $imageSource = null;

            // Check passed path
            if ($this->localPath) {
                Log::channel('single')->info('📁 Checking passed path: ' . $this->localPath);
                if (file_exists($this->localPath)) {
                    $imageData = file_get_contents($this->localPath);
                    $imageSource = 'passed_path';
                    Log::channel('single')->info('✅ Loaded from passed path, size: ' . strlen($imageData));
                }
            }

            // Check storage path
            if (!$imageData && $analysis->image_path) {
                $fullPath = storage_path('app/public/' . $analysis->image_path);
                Log::channel('single')->info('📁 Checking storage path: ' . $fullPath);
                if (file_exists($fullPath)) {
                    $imageData = file_get_contents($fullPath);
                    $imageSource = 'storage_path';
                    Log::channel('single')->info('✅ Loaded from storage path, size: ' . strlen($imageData));
                } else {
                    Log::channel('single')->warning('⚠️ File not found: ' . $fullPath);
                    // Try to list what's in the directory
                    $dir = dirname($fullPath);
                    if (is_dir($dir)) {
                        $files = scandir($dir);
                        Log::channel('single')->info('📂 Files in directory: ' . implode(', ', array_slice($files, 0, 10)));
                    }
                }
            }

            // Check Laravel Storage
            if (!$imageData && $analysis->image_path) {
                Log::channel('single')->info('📁 Checking Laravel Storage: ' . $analysis->image_path);
                if (Storage::disk('public')->exists($analysis->image_path)) {
                    $imageData = Storage::disk('public')->get($analysis->image_path);
                    $imageSource = 'laravel_storage';
                    Log::channel('single')->info('✅ Loaded from Laravel Storage, size: ' . strlen($imageData));
                }
            }

            // Check URL
            if (!$imageData && $analysis->image_url) {
                Log::channel('single')->info('📸 Checking URL: ' . $analysis->image_url);
                $imageData = @file_get_contents($analysis->image_url);
                if ($imageData) {
                    $imageSource = 'url';
                    Log::channel('single')->info('✅ Loaded from URL, size: ' . strlen($imageData));
                }
            }

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

            Log::channel('single')->info('✅ Image loaded successfully from: ' . $imageSource);

            // ✅ STEP 3: Update to AI Analysis
            $analysis->update([
                'stage' => 2,
                'stage_label' => 'AI Analysis',
                'progress' => 40,
            ]);
            Log::channel('single')->info('✅ Updated to stage 2');

            // ✅ STEP 4: Get metadata
            $metadata = [];
            if ($analysis->image_path) {
                $fullPath = storage_path('app/public/' . $analysis->image_path);
                if (file_exists($fullPath)) {
                    $metadata = $this->extractMetadata($fullPath);
                    Log::channel('single')->info('✅ Metadata extracted', ['has_gps' => isset($metadata['gps'])]);
                }
            }

            // ✅ STEP 5: Call Gemini API
            Log::channel('single')->info('🤖 Calling Gemini API...');
            $aiResult = $geminiService->analyzeGeolocation($imageData, $metadata);

            if (isset($aiResult['error'])) {
                throw new \Exception($aiResult['message'] ?? 'AI analysis failed');
            }

            Log::channel('single')->info('✅ Gemini API returned', [
                'landmark' => $aiResult['landmark_name'] ?? 'Unknown',
                'confidence' => $aiResult['confidence'] ?? 0,
            ]);

            // ✅ STEP 6: Update to Cloudinary
            $analysis->update([
                'stage' => 3,
                'stage_label' => 'Uploading to Cloudinary',
                'progress' => 70,
            ]);
            Log::channel('single')->info('✅ Updated to stage 3');

            // ✅ STEP 7: Upload to Cloudinary
            $cloudinaryUrl = null;
            $localFile = null;

            if ($this->localPath && file_exists($this->localPath)) {
                $localFile = $this->localPath;
            } elseif ($analysis->image_path) {
                $fullPath = storage_path('app/public/' . $analysis->image_path);
                if (file_exists($fullPath)) {
                    $localFile = $fullPath;
                }
            }

            if ($localFile) {
                try {
                    Log::channel('single')->info('☁️ Uploading to Cloudinary...');
                    Configuration::instance([
                        'cloud' => [
                            'cloud_name' => 'hyv3laps',
                            'api_key' => '189951824121921',
                            'api_secret' => env('CLOUDINARY_API_SECRET'),
                        ],
                        'url' => ['secure' => true],
                    ]);

                    $uploadApi = new UploadApi();
                    $uploadResult = $uploadApi->upload($localFile, [
                        'folder' => 'tracegeo/analyses',
                        'public_id' => pathinfo($this->filename ?? $analysis->image_path ?? 'image', PATHINFO_FILENAME),
                    ]);
                    
                    $cloudinaryUrl = $uploadResult['secure_url'];
                    Log::channel('single')->info('✅ Cloudinary upload successful', ['url' => $cloudinaryUrl]);
                    
                    if (file_exists($localFile)) {
                        @unlink($localFile);
                        Log::channel('single')->info('🗑️ Local file deleted');
                    }
                } catch (\Exception $e) {
                    Log::channel('single')->error('❌ Cloudinary upload failed: ' . $e->getMessage());
                }
            }

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

            try {
                $analysis = GeoAnalysis::find($this->analysisId);
                if ($analysis) {
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

            throw $e;
        }
    }

    public function failed(\Exception $exception)
    {
        Log::channel('single')->error('❌❌❌ Job PERMANENTLY FAILED after ' . $this->tries . ' attempts:', [
            'analysis_id' => $this->analysisId,
            'error' => $exception->getMessage(),
        ]);

        try {
            $analysis = GeoAnalysis::find($this->analysisId);
            if ($analysis) {
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

    private function extractMetadata($filePath)
    {
        $data = [];
        if (!$filePath || !file_exists($filePath)) return $data;

        if (function_exists('exif_read_data')) {
            try {
                $exif = @exif_read_data($filePath);
                if ($exif) {
                    if (isset($exif['GPSLatitude']) && isset($exif['GPSLongitude'])) {
                        $data['gps'] = [
                            'latitude' => $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N'),
                            'longitude' => $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E')
                        ];
                    }
                    $data['camera'] = [
                        'make' => $exif['Make'] ?? null,
                        'model' => $exif['Model'] ?? null,
                    ];
                    $data['datetime'] = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
                }
            } catch (\Exception $e) {
                Log::channel('single')->warning('⚠️ Could not extract EXIF: ' . $e->getMessage());
            }
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
}