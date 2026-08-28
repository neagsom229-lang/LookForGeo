<?php

namespace App\Http\Controllers;

use App\Services\LandmarkRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class GeoAnalysisController extends Controller
{
    protected $landmarkService;

    public function __construct(LandmarkRecognitionService $landmarkService)
    {
        $this->landmarkService = $landmarkService;
    }

    public function index()
    {
        return view('analysis');
    }

    /**
     * POST /api/analyze - Upload and analyze image
     */
    public function analyze(Request $request)
    {
        Log::info('📸 [ANALYZE] Endpoint called');

        try {
            $request->validate([
                'image' => 'required|image|max:5120|mimes:jpeg,png,jpg,webp',
            ]);

            $filename = time() . '_' . uniqid() . '.jpg';
            $path = $request->file('image')->storeAs('analyses', $filename, 'public');
            $fullPath = Storage::disk('public')->path($path);

            Log::info('📸 [ANALYZE] Image saved: ' . $fullPath);

            // ✅ CALL THE SERVICE
            $result = $this->landmarkService->identify($fullPath, 'detailed', []);
            
            Log::info('🤖 [ANALYZE] Result: ' . json_encode($result));

            // ✅ CHECK FOR ERROR
            if (isset($result['error_code']) && $result['error_code'] !== null) {
                Log::error('❌ [ANALYZE] Service error: ' . ($result['description'] ?? 'Unknown'));
                return response()->json([
                    'success' => false,
                    'message' => $result['description'] ?? 'Analysis failed',
                ], 422);
            }

            // ✅ ADD IMAGE URL
            $result['image_url'] = Storage::url($path);
            $result['_timestamp'] = now()->toDateTimeString();

            // ✅ ✅ ✅ STORE IN CACHE WITH MULTIPLE KEYS
            Cache::put('latest_analysis', $result, 3600);
            Cache::put('analysis_data', $result, 3600);
            Cache::put('analysis_result', $result, 3600);
            Cache::put('analysis_timestamp', now()->timestamp, 3600);

            Log::info('✅ [ANALYZE] Success! Stored in cache: ' . ($result['landmark_name'] ?? 'null'));

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => '✅ Analysis complete!',
            ]);

        } catch (\Exception $e) {
            Log::error('❌ [ANALYZE] Exception: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/analysis-data - Get the latest analysis result
     */
    public function analysisData()
    {
        Log::info('📦 [DATA] Called');
        
        // ✅ TRY MULTIPLE CACHE KEYS
        $data = Cache::get('latest_analysis');
        if (!$data) {
            $data = Cache::get('analysis_data');
        }
        if (!$data) {
            $data = Cache::get('analysis_result');
        }
        
        $timestamp = Cache::get('analysis_timestamp', 0);
        $isFresh = (time() - $timestamp) < 3600;

        Log::info('📦 [DATA] Data exists: ' . ($data ? 'YES' : 'NO'));
        Log::info('📦 [DATA] Is fresh: ' . ($isFresh ? 'YES' : 'NO'));

        if ($data && !empty($data['landmark_name']) && $data['landmark_name'] !== 'Unknown Location' && $isFresh) {
            Log::info('📦 [DATA] Returning: ' . $data['landmark_name']);
            return response()->json([
                'success' => true,
                'data' => $data,
                'fresh' => true,
            ]);
        }

        Log::info('📦 [DATA] No fresh data found');
        return response()->json([
            'success' => false,
            'message' => 'No fresh analysis data. Please upload an image.',
            'data' => null,
        ]);
    }

    public function clearCache()
    {
        Cache::forget('latest_analysis');
        Cache::forget('analysis_data');
        Cache::forget('analysis_result');
        Cache::forget('analysis_timestamp');
        Log::info('🧹 Cache cleared');
        return response()->json([
            'success' => true,
            'message' => '✅ Cache cleared!',
        ]);
    }

    public function streetView(Request $request)
    {
        $lat = $request->input('lat', 10.4837);
        $lng = $request->input('lng', 104.2942);
        
        return response()->json([
            'embed_url' => "https://www.google.com/maps/embed?pb=!1m4!1m3!1m0!2d{$lng}!3d{$lat}!1m3!2m2!1d{$lng}!2d{$lat}!3e4!5m1!1e4!6m1!1e1",
            'street_view_url' => "https://www.google.com/maps/@?api=1&map_action=pano&viewpoint={$lat},{$lng}",
        ]);
    }
    /**
 * New async entry point: uploads to Cloudinary, creates a GeoAnalysis
 * row, dispatches the background job, and returns immediately.
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

        Configuration::instance([
            'cloud' => [
                'cloud_name' => 'hyv3laps',
                'api_key'    => '189951824121921',
                'api_secret' => env('CLOUDINARY_API_SECRET'),
            ],
            'url' => ['secure' => true],
        ]);

        $uploadApi = new UploadApi();
        $result = $uploadApi->upload($file->getRealPath(), [
            'folder' => 'tracegeo/analyses',
            'public_id' => pathinfo($filename, PATHINFO_FILENAME),
        ]);

        $imageUrl = $result['secure_url'];

        $analysis = GeoAnalysis::create([
            'status'    => 'processing',
            'stage'     => 0,
            'stage_label' => 'Input',
            'progress'  => 0,
            'image_url' => $imageUrl,
        ]);

        AnalyzeImageJob::dispatch($analysis->id);

        return response()->json([
            'success' => true,
            'id' => $analysis->id,
        ]);

    } catch (\Exception $e) {
        \Log::error('Analysis store error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error starting analysis: ' . $e->getMessage()
        ], 500);
    }
}

/**
 * Polled by the frontend every 700ms. Reports real job progress.
 */
public function status($id)
{
    $analysis = GeoAnalysis::find($id);

    if (!$analysis) {
        return response()->json(['success' => false, 'message' => 'Not found'], 404);
    }

    return response()->json([
        'status'      => $analysis->status,
        'stage'       => $analysis->stage,
        'stage_label' => $analysis->stage_label,
        'progress'    => $analysis->progress,
        'elapsed'     => $analysis->elapsedSeconds(),
        'result'      => $analysis->result,
        'error'       => $analysis->error,
    ]);
}
}