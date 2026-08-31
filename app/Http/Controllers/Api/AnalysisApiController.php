<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GeoAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AnalysisApiController extends Controller
{
    // 1. Health Check Endpoint
    public function health()
    {
        return response()->json([
            'success' => true,
            'status' => 'operational',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString()
        ]);
    }

    // 2. History Endpoint (GET)
    public function history(Request $request)
    {
        $analyses = GeoAnalysis::where('user_id', $request->user()->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $analyses->map(function ($analysis) {
                // ✅ FIX: Decode result if it's a JSON string
                $result = is_string($analysis->result) 
                    ? json_decode($analysis->result, true) 
                    : $analysis->result;

                return [
                    'id' => $analysis->id,
                    'landmark' => $result['landmark_name'] ?? 'Unknown',
                    'coordinates' => [
                        'lat' => $result['latitude'] ?? null,
                        'lng' => $result['longitude'] ?? null,
                    ],
                    'confidence' => $result['confidence'] ?? 0,
                    'image' => $analysis->image_url ?? asset('storage/' . $analysis->image_path),
                    'created_at' => $analysis->created_at,
                ];
            }),
            'pagination' => [
                'current_page' => $analyses->currentPage(),
                'last_page' => $analyses->lastPage()
            ]
        ]);
    }

    // 3. Analyze Endpoint (POST)
    public function analyze(Request $request)
    {
        $request->validate([
            'image' => 'required|file|mimes:jpeg,png,jpg,gif,webp|max:20480'
        ]);

        // Dispatch to your existing job
        $file = $request->file('image');
        $path = $file->store('uploads/analyses', 'public');
        $imageUrl = asset('storage/' . $path);
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

        return response()->json([
            'success' => true,
            'message' => 'Analysis started. Poll this ID to get results.',
            'analysis_id' => $analysis->id
        ], 202);
    }
}