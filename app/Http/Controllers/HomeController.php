<?php

namespace App\Http\Controllers;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
            if (! auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please login to view dashboard data',
                ], 401);
            }

            $userId = auth()->id();

            // Personal block cached per user; the popular-landmarks block is
            // global (same for everyone) so cache it once and share it
            // instead of recomputing it for every user's cache entry.
            $personal = Cache::remember('dashboard_'.$userId, 300, function () use ($userId) {
                return $this->buildPersonalData($userId);
            });
            $popular = Cache::remember('dashboard_popular_global', 300, function () {
                return $this->buildPopularData();
            });

            return response()->json(array_merge($personal, ['popular' => $popular]));

        } catch (\Exception $e) {
            Log::error('Dashboard error: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error loading dashboard data: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Build the per-user dashboard block (stats + recent analyses).
     * Each row's JSON result is parsed exactly once.
     */
    private function buildPersonalData($userId)
    {
        // Get all completed analyses for the user
        $analyses = GeoAnalysis::where('user_id', $userId)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->get();

        // Parse once and reuse everywhere below
        $parsed = $analyses->map(fn ($analysis) => $this->parseResult($analysis->result));

        // Stats
        $totalAnalyses = $analyses->count();

        $uniqueLocations = $parsed
            ->map(fn ($result) => ($result['latitude'] ?? 0).','.($result['longitude'] ?? 0))
            ->unique()
            ->count();

        $avgConfidence = $parsed->avg(fn ($result) => $result['confidence'] ?? 0);

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

        // User info
        $user = User::find($userId);

        return [
            'success' => true,
            'stats' => $stats,
            'recent' => $recent,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }

    /**
     * Build the global popular-landmarks block.
     * Cached once and shared across all users.
     */
    private function buildPopularData()
    {
        return GeoAnalysis::where('status', 'completed')
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
        // 1. Check result JSON for image_url (Cloudinary)
        $result = $this->parseResult($analysis->result);
        if (! empty($result['image_url']) && filter_var($result['image_url'], FILTER_VALIDATE_URL)) {
            return $result['image_url'];
        }

        // 2. Check model's image_url (Cloudinary or full URL)
        if (! empty($analysis->image_url) && filter_var($analysis->image_url, FILTER_VALIDATE_URL)) {
            return $analysis->image_url;
        }

        // 3. Fallback to local image_path – but only if the file exists
        if (! empty($analysis->image_path)) {
            if (filter_var($analysis->image_path, FILTER_VALIDATE_URL)) {
                return $analysis->image_path;
            }
            // ✅ Only generate asset URL if the file actually exists
            if (Storage::disk('public')->exists($analysis->image_path)) {
                return Storage::disk('public')->url($analysis->image_path);
            }
            // If the file is missing, return null (front‑end can show placeholder)
        }

        // 4. Nothing found – return null
        return null;
    }

    // ============================================================
    // Optional: Clear cache after analysis
    // ============================================================
    public static function clearDashboardCache($userId)
    {
        Cache::forget('dashboard_'.$userId);
        Cache::forget('dashboard_popular_global');
    }
}
