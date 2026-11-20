<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnalysisImageRequest;
use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Services\AnalysisCostGuard;
use App\Services\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnalysisApiController extends Controller
{
    public function health(SystemHealthService $health)
    {
        $report = $health->checks();

        return response()->json([
            'success' => true,
            'status' => $report['status'],
            'version' => '1.0.0',
            'commit' => substr((string) config('app.deploy_commit'), 0, 7),
            'timestamp' => now()->toISOString(),
            'checks' => $report['checks'],
        ]);
    }

    public function history(Request $request)
    {
        $analyses = GeoAnalysis::where('user_id', $request->user()->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $analyses->map(function ($analysis) {
                $result = is_string($analysis->result)
                    ? json_decode($analysis->result, true)
                    : $analysis->result;

                // Use image_url if available, else generate from image_path
                $imageUrl = $analysis->image_url ??
                    ($analysis->image_path ? Storage::disk('public')->url($analysis->image_path) : null);

                return [
                    'id' => $analysis->id,
                    'landmark' => $result['landmark_name'] ?? 'Unknown',
                    'coordinates' => [
                        'lat' => $result['latitude'] ?? null,
                        'lng' => $result['longitude'] ?? null,
                    ],
                    'confidence' => $result['confidence'] ?? 0,
                    'image' => $imageUrl,
                    'created_at' => $analysis->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $analyses->currentPage(),
                'last_page' => $analyses->lastPage(),
            ],
        ]);
    }

    public function analyze(StoreAnalysisImageRequest $request)
    {
        $file = $request->file('image');

        // Repeat-upload cache (same rule as the session endpoint): identical
        // bytes from this user return the completed row with zero AI cost.
        $imageHash = hash_file('sha256', $file->getRealPath());
        $cached = GeoAnalysis::findCachedForUser($imageHash, (int) $request->user()->id);
        if ($cached) {
            return response()->json([
                'success' => true,
                'message' => 'Analysis already completed.',
                'id' => $cached->id,
                'cached' => true,
                'data' => [
                    'id' => $cached->id,
                    'status' => 'completed',
                    'image_url' => $cached->image_url,
                    'result' => $cached->result,
                ],
            ], 200);
        }

        $costGuard = app(AnalysisCostGuard::class);
        if ($blocked = $costGuard->blocked((int) $request->user()->id)) {
            return response()->json([
                'success' => false,
                'message' => $blocked['message'],
            ], 429)->header('Retry-After', (string) $blocked['retry_after']);
        }

        // ✅ Wrap storage operation in try-catch to handle errors gracefully
        try {
            $path = $file->store('uploads/analyses', 'public');
        } catch (\Exception $e) {
            \Log::error('File upload failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to store the uploaded image. Please try again.',
            ], 500);
        }

        // Verify the file was actually stored
        if (! $path || ! Storage::disk('public')->exists($path)) {
            \Log::error('File storage verification failed: path not found', ['path' => $path]);

            return response()->json([
                'success' => false,
                'message' => 'Image was not stored successfully. Please try again.',
            ], 500);
        }

        // Generate URL using Storage facade (respects public disk config)
        $imageUrl = Storage::disk('public')->url($path);
        $fullPath = Storage::disk('public')->path($path);

        $analysis = GeoAnalysis::create([
            'user_id' => $request->user()->id,
            'status' => 'processing',
            'image_path' => $path,
            'image_url' => $imageUrl,
            'image_hash' => $imageHash,
            'progress' => 10,
            'stage' => 0,
        ]);

        AnalyzeImageJob::dispatch($analysis->id, $fullPath, $file->getClientOriginalName());
        $costGuard->record((int) $request->user()->id);

        // ✅ Return the same key expected by front-end: 'id'
        return response()->json([
            'success' => true,
            'message' => 'Analysis started. Poll this ID to get results.',
            'id' => $analysis->id,
            'data' => [
                'image_url' => $imageUrl,
            ],
        ], 202);
    }
}
