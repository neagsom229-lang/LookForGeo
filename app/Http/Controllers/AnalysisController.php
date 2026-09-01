<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Http\Request;
use App\Models\GeoAnalysis;
use App\Jobs\AnalyzeImageJob;
use App\Models\Analysis;
use App\Services\GeminiService;

class AnalysisController extends Controller
{
    protected $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Show the analysis page
     */
    public function index()
{
    $googleMapsEmbedKey = config('services.google_maps.embed_key');
    // Ensure it's a string
    if (!is_string($googleMapsEmbedKey)) {
        $googleMapsEmbedKey = '';
    }
    return view('analysis', ['googleMapsEmbedKey' => $googleMapsEmbedKey]);
}

    /**
     * Show history page (filtered by logged-in user)
     */
    public function history()
    {
        $analyses = GeoAnalysis::where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('history', ['analyses' => $analyses]);
    }

    /**
     * ✅ ASYNC UPLOAD – Stores file, creates record, dispatches job
     */
    public function store(Request $request)
{
    if (!auth()->check()) {
        return response()->json([
            'success' => false,
            'message' => 'Please login to upload images.'
        ], 401);
    }

    $request->validate([
        'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480',
    ]);

    try {
        $file = $request->file('image');
        $filename = time() . '_' . $file->getClientOriginalName();

        Log::info('🚀 Starting async upload', [
            'user_id' => auth()->id(),
            'filename' => $filename,
            'size' => $file->getSize()
        ]);

                // Store the file locally (Bypasses symlink - Saves directly to public/uploads)
       $path = $file->store('uploads/analyses', 'public');
$imageUrl = asset('storage/' . $path);
$fullPath = Storage::disk('public')->path($path); // ✅ CRITICAL FIX

        Log::info('📁 File stored locally', [
            'path' => $path,
            'full_path' => $fullPath,
            'url' => $imageUrl,
            'file_exists' => file_exists($fullPath)
        ]);

        // Create the record
        $analysis = GeoAnalysis::create([
            'user_id' => auth()->id(),
            'status' => 'processing',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => $path,
            'image_url' => $imageUrl,
            'started_at' => now(),
        ]);

        Log::info('✅ GeoAnalysis created with ID: ' . $analysis->id);

        // ============================================================
        // ✅ DISPATCH JOB WITH TRY-CATCH
        // ============================================================
        try {
            AnalyzeImageJob::dispatch($analysis->id, $fullPath, $filename);
            Log::info('✅ Job dispatched successfully for analysis ID: ' . $analysis->id);
        } catch (\Exception $jobException) {
            Log::error('❌ Job dispatch FAILED: ' . $jobException->getMessage());
            Log::error('Stack trace: ' . $jobException->getTraceAsString());

            // Update the analysis to failed since the job couldn't be dispatched
            $analysis->update([
                'status' => 'failed',
                'error' => 'Job dispatch failed: ' . $jobException->getMessage(),
                'finished_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Analysis could not be started: ' . $jobException->getMessage()
            ], 500);
        }

        return response()->json([
            'success' => true,
            'id' => $analysis->id,
            'message' => 'Analysis started successfully',
            'data' => [
                'id' => $analysis->id,
                'status' => 'processing',
                'progress' => 10,
                'image_url' => $imageUrl,
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('❌ Analysis store error: ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());

        return response()->json([
            'success' => false,
            'message' => 'Error starting analysis: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * ✅ POLLING ENDPOINT – No ownership check (can be public once ID is known)
     */
    public function status($id)
    {
        try {
            $analysis = GeoAnalysis::find($id);

            if (!$analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found'
                ], 404);
            }

            $elapsed = 0;
            if ($analysis->started_at) {
                $end = $analysis->finished_at ?? now();
                $elapsed = round($analysis->started_at->diffInSeconds($end), 1);
            }

            $response = [
                'success' => true,
                'status' => $analysis->status,
                'stage' => $analysis->stage ?? 0,
                'stage_label' => $analysis->stage_label ?? 'Processing',
                'progress' => $analysis->progress ?? 0,
                'elapsed' => $elapsed,
                'image_url' => $analysis->image_url,
            ];

            if ($analysis->status === 'completed' && $analysis->result) {
                $result = $analysis->result;
                if (is_string($result)) {
                    $result = json_decode($result, true);
                }
                $response['result'] = $result;
                $response['data'] = [
                    'id' => $analysis->id,
                    'landmark_name' => $result['landmark_name'] ?? 'Unknown',
                    'city' => $result['city'] ?? null,
                    'country' => $result['country'] ?? null,
                    'latitude' => $result['latitude'] ?? null,
                    'longitude' => $result['longitude'] ?? null,
                    'confidence' => $result['confidence'] ?? 0,
                    'description' => $result['description'] ?? null,
                    'image_url' => $analysis->image_url,
                    'result_image_url' => $result['image_url'] ?? null, // ✅ add this line
                    'tags' => $result['tags'] ?? [],
                    'reasoning' => $result['reasoning'] ?? null,
                ];
            }

            if ($analysis->status === 'failed') {
                $response['error'] = $analysis->error;
            }

            return response()->json($response);

        } catch (\Exception $e) {
            Log::error('❌ Status fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching status: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📊 API: Get all analyses for the authenticated user
     */
    public function getHistory(Request $request)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to view history.'
            ], 401);
        }

        try {
            $analyses = GeoAnalysis::where('user_id', auth()->id())
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'data' => $analyses->map(function ($analysis) {
                    $data = [
                        'id' => $analysis->id,
                        'status' => $analysis->status,
                        'progress' => $analysis->progress,
                        'image_url' => $analysis->image_url,
                        'created_at' => $analysis->created_at->toDateTimeString(),
                        'finished_at' => $analysis->finished_at?->toDateTimeString(),
                    ];

                    if ($analysis->status === 'completed' && $analysis->result) {
                        $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
                        $data['landmark_name'] = $result['landmark_name'] ?? 'Unknown';
                        $data['city'] = $result['city'] ?? null;
                        $data['country'] = $result['country'] ?? null;
                        $data['confidence'] = $result['confidence'] ?? 0;
                    }

                    return $data;
                }),
                'pagination' => [
                    'current_page' => $analyses->currentPage(),
                    'last_page' => $analyses->lastPage(),
                    'per_page' => $analyses->perPage(),
                    'total' => $analyses->total(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('❌ History fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching history: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🗑️ Delete an analysis (ownership check)
     */
    public function destroy($id)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to delete analysis.'
            ], 401);
        }

        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found or does not belong to you.'
                ], 404);
            }

                        // Delete local file if exists
            if ($analysis->image_path && Storage::disk('public_uploads')->exists($analysis->image_path)) {
                Storage::disk('public_uploads')->delete($analysis->image_path);
            }

            $analysis->delete();

            return response()->json([
                'success' => true,
                'message' => 'Analysis deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Delete error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error deleting analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🔄 Retry a failed analysis (ownership check)
     */
    public function retry($id)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to retry analysis.'
            ], 401);
        }

        try {
            $analysis = GeoAnalysis::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$analysis) {
                return response()->json([
                    'success' => false,
                    'message' => 'Analysis not found or does not belong to you.'
                ], 404);
            }

            if ($analysis->status !== 'failed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Only failed analyses can be retried.'
                ], 400);
            }

            // Reset and dispatch again
            $analysis->update([
                'status' => 'processing',
                'stage' => 0,
                'stage_label' => 'Retrying',
                'progress' => 10,
                'error' => null,
                'finished_at' => null,
                'started_at' => now(),
            ]);

            AnalyzeImageJob::dispatch($analysis->id);

            return response()->json([
                'success' => true,
                'id' => $analysis->id,
                'message' => 'Analysis retry started'
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Retry error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error retrying analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * ⚡ SYNC ANALYZE – Legacy method (backward compatibility)
     * Note: This also creates a record with user_id now.
     */
    public function analyze(Request $request)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to upload images.'
            ], 401);
        }

        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480',
        ]);

        try {
            $file = $request->file('image');
            $filename = time() . '_' . $file->getClientOriginalName();

                        $path = $file->store('uploads/analyses', 'public_uploads');
            $imageUrl = asset('uploads/' . $path);

            $metadata = $this->extractFullMetadata($file);
            $imageData = $this->getImageData($file);

            $aiResult = $this->geminiService->analyzeGeolocation($imageData, $metadata);

            if (isset($aiResult['error'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'AI analysis failed: ' . ($aiResult['message'] ?? 'Unknown error')
                ], 500);
            }

            // ✅ ADDED user_id here
            $analysis = GeoAnalysis::create([
                'user_id' => auth()->id(),
                'status' => 'completed',
                'stage' => 4,
                'stage_label' => 'Complete',
                'progress' => 100,
                'image_path' => $path,
                'image_url' => $imageUrl,
                'result' => json_encode(array_merge($aiResult, ['image_url' => $imageUrl])),
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'id' => $analysis->id,
                'data' => array_merge($aiResult, [
                    'id' => $analysis->id,
                    'image_url' => $imageUrl,
                ])
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Sync analysis error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error processing analysis: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 📸 Get image data with compression
     */
    private function getImageData($file)
    {
        if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
            try {
                $compressed = $this->compressImage($file);
                if ($compressed) {
                    return $compressed;
                }
            } catch (\Exception $e) {
                Log::warning('Image compression failed, using original: ' . $e->getMessage());
            }
        }
        return file_get_contents($file->path());
    }

    /**
     * 🖼️ Compress image to reduce size
     */
    private function compressImage($file)
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }

        $image = @imagecreatefromstring(file_get_contents($file->path()));
        if (!$image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $maxDimension = 1024;
        if ($width > $maxDimension || $height > $maxDimension) {
            $ratio = min($maxDimension / $width, $maxDimension / $height);
            $newWidth = (int) ($width * $ratio);
            $newHeight = (int) ($height * $ratio);
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($newImage, null, 80);
        $compressed = ob_get_clean();

        imagedestroy($image);
        imagedestroy($newImage);

        return $compressed;
    }

    /**
     * 📋 Extract EXIF metadata
     */
    private function extractFullMetadata($file)
    {
        $data = [];

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($file->path());
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
                $data['software'] = $exif['Software'] ?? null;
                $data['copyright'] = $exif['Copyright'] ?? null;
                $data['settings'] = [
                    'aperture' => $exif['ApertureFNumber'] ?? null,
                    'iso' => $exif['ISOSpeedRatings'] ?? null,
                    'focal_length' => $exif['FocalLength'] ?? null,
                ];
            }
        }

        return $data;
    }

    /**
     * 📍 Convert GPS to decimal
     */
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
     * 🌍 Get street view URL
     */
    public function streetView(Request $request)
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric'
        ]);

        $lat = $request->input('lat');
        $lng = $request->input('lng');

        return response()->json([
            'success' => true,
            'url' => "https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={$lat},{$lng}"
        ]);
    }

    /**
     * 🖼️ Fetch image from URL
     */
    public function fetchImage(Request $request)
    {
        if (!auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to fetch images.'
            ], 401);
        }

        $request->validate(['url' => 'required|url']);

        try {
            $response = Http::timeout(30)->get($request->input('url'));

            if (!$response->successful()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Could not fetch image from URL: HTTP ' . $response->status()
                ], 400);
            }

            $content = $response->body();
            $contentType = $response->header('Content-Type');

            if (!str_starts_with($contentType, 'image/')) {
                return response()->json([
                    'success' => false,
                    'message' => 'URL does not point to an image. Content-Type: ' . $contentType
                ], 400);
            }

            $extension = match($contentType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg'
            };

            $filename = 'url_' . time() . '.' . $extension;

            return response()->json([
                'success' => true,
                'image_data' => base64_encode($content),
                'mime_type' => $contentType,
                'filename' => $filename,
            ]);

        } catch (\Exception $e) {
            Log::error('URL fetch error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching image: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get analysis results by ID (no ownership check – can be public)
     */
    public function getResults($id)
    {
        try {
            $analysis = GeoAnalysis::findOrFail($id);

            $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $analysis->id,
                    'landmark_name' => $result['landmark_name'] ?? 'Unknown',
                    'local_name' => $result['local_name'] ?? null,
                    'city' => $result['city'] ?? null,
                    'country' => $result['country'] ?? null,
                    'latitude' => $result['latitude'] ?? null,
                    'longitude' => $result['longitude'] ?? null,
                    'confidence' => $result['confidence'] ?? 0,
                    'description' => $result['description'] ?? null,
                    'type' => $result['type'] ?? null,
                    'image_url' => $analysis->image_url,
                    'tags' => $result['tags'] ?? [],
                    'reasoning' => $result['reasoning'] ?? null,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Analysis not found'
            ], 404);
        }
    }

    /**
     * Get session analysis data
     */
    public function getSessionData(Request $request)
    {
        $result = $request->session()->get('analysis_result');
        return $result ? response()->json(['success' => true, 'data' => $result])
                       : response()->json(['success' => false, 'message' => 'No data found'], 404);
    }

    /**
     * Clear analysis cache
     */
    public function clearCache(Request $request)
    {
        $request->session()->forget('analysis_result');
        return response()->json(['success' => true, 'message' => 'Cache cleared']);
    }
}