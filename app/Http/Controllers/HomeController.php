<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Models\Landmark;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HomeController extends Controller
{
    /**
     * Show the home page
     */
    public function index()
    {
        return view('home');
    }

    /**
     * Get dashboard data for the logged-in user
     * Uses the GeoAnalysis model with JSON result parsing
     */
    public function dashboardData()
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please login to view dashboard data'
                ], 401);
            }

            $userId = auth()->id();

            // Cache for 5 minutes to reduce DB load
            $cacheKey = 'dashboard_' . $userId;
            $data = Cache::remember($cacheKey, 300, function () use ($userId) {
                return $this->buildDashboardData($userId);
            });

            return response()->json($data);

        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading dashboard data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build the dashboard data from GeoAnalysis records
     */
    private function buildDashboardData($userId)
    {
        // Get all completed analyses for the user
        $analyses = GeoAnalysis::where('user_id', $userId)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        // Stats
        $totalAnalyses = $analyses->count();

        // Unique locations based on distinct (latitude, longitude) from result JSON
        $uniqueLocations = $analyses->unique(function ($item) {
            $result = $this->parseResult($item->result);
            return ($result['latitude'] ?? 0) . ',' . ($result['longitude'] ?? 0);
        })->count();

        // Average confidence from result JSON
        $avgConfidence = $analyses->avg(function ($item) {
            $result = $this->parseResult($item->result);
            return $result['confidence'] ?? 0;
        });

        $stats = [
            'total_analyses' => $totalAnalyses,
            'unique_locations' => $uniqueLocations,
            'avg_confidence' => round($avgConfidence, 1),
        ];

        // Recent analyses (6 latest)
        $recent = $analyses->take(6)->map(function ($item) {
            $result = $this->parseResult($item->result);
            $imageUrl = $this->getImageUrl($item);

            return [
                'id' => $item->id,
                'landmark_name' => $result['landmark_name'] ?? 'Unknown Location',
                'city' => $result['city'] ?? '',
                'country' => $result['country'] ?? '',
                'confidence' => $result['confidence'] ?? 0,
                'image_path' => $imageUrl,       // ✅ Full URL (Cloudinary or asset)
                'image_url' => $imageUrl,        // ✅ Also provide as 'image_url' for flexibility
            ];
        });

        // Popular landmarks across all users (global)
        $popular = GeoAnalysis::where('status', 'completed')
            ->whereNotNull('result')
            ->get()
            ->groupBy(function ($item) {
                $result = $this->parseResult($item->result);
                return $result['landmark_name'] ?? 'Unknown';
            })
            ->map(function ($group, $name) {
                $first = $group->first();
                $result = $this->parseResult($first->result);
                return [
                    'landmark_name' => $name,
                    'country' => $result['country'] ?? '',
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5)
            ->values()
            ->toArray();

        // User info
        $user = User::find($userId);

        return [
            'success' => true,
            'stats' => $stats,
            'recent' => $recent,
            'popular' => $popular,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }

    /**
     * Safely parse the JSON result field
     */
    private function parseResult($result)
    {
        if (empty($result)) {
            return [];
        }

        if (is_string($result)) {
            try {
                return json_decode($result, true) ?? [];
            } catch (\Exception $e) {
                return [];
            }
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Get the correct image URL for display – ALWAYS returns a full, absolute URL.
     * Priority: image_url > image_path (local) > result.image_url > null
     */
    private function getImageUrl($analysis)
    {
        // 1. If the model has a direct image_url field (Cloudinary or full asset URL)
        if (!empty($analysis->image_url)) {
            // If it's already a full URL, return it
            if (filter_var($analysis->image_url, FILTER_VALIDATE_URL)) {
                return $analysis->image_url;
            }
            // Otherwise, assume it's a relative path and convert to asset
            return asset($analysis->image_url);
        }

        // 2. Fallback to image_path
        if (!empty($analysis->image_path)) {
            // If it's already a full URL, return it
            if (filter_var($analysis->image_path, FILTER_VALIDATE_URL)) {
                return $analysis->image_path;
            }
            // Otherwise, assume it's a storage path
            return asset('storage/' . $analysis->image_path);
        }

        // 3. Check the result JSON for an image_url
        $result = $this->parseResult($analysis->result);
        if (!empty($result['image_url']) && filter_var($result['image_url'], FILTER_VALIDATE_URL)) {
            return $result['image_url'];
        }

        // 4. If everything fails, return a placeholder
        return null;
    }

    // ============================================================
    // Optional: Clear cache after analysis
    // ============================================================
    public static function clearDashboardCache($userId)
    {
        Cache::forget('dashboard_' . $userId);
    }
}