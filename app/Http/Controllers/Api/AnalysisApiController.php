<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnalysisApiController extends Controller
{
    public function health()
    {
        return response()->json([
            'success' => true,
            'status' => 'operational',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString()
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
                'last_page' => $analyses->lastPage()
            ]
        ]);
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480'
        ]);

        $file = $request->file('image');
        
        // ✅ Wrap storage operation in try-catch to handle errors gracefully
        try {
            $path = $file->store('uploads/analyses', 'public');
        } catch (\Exception $e) {
            \Log::error('File upload failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to store the uploaded image. Please try again.'
            ], 500);
        }

        // Verify the file was actually stored
        if (!$path || !Storage::disk('public')->exists($path)) {
            \Log::error('File storage verification failed: path not found', ['path' => $path]);
            return response()->json([
                'success' => false,
                'message' => 'Image was not stored successfully. Please try again.'
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
            'progress' => 10,
            'stage' => 0,
        ]);

        \App\Jobs\AnalyzeImageJob::dispatch($analysis->id, $fullPath, $file->getClientOriginalName());

        // ✅ Return the same key expected by front-end: 'id'
        return response()->json([
            'success' => true,
            'message' => 'Analysis started. Poll this ID to get results.',
            'id' => $analysis->id,
            'data' => [
                'image_url' => $imageUrl,
            ]
        ], 202);
    }
}