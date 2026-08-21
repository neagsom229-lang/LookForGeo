<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Analysis;
use App\Models\User;
use App\Models\Landmark;
use Illuminate\Support\Facades\DB;

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
     * Uses simple DB queries for reliability
     */
    public function dashboardData()
    {
        try {
            // Check if user is logged in
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please login to view dashboard data'
                ], 401);
            }

            $userId = auth()->id();

            // Get stats using DB facade (bypasses potential model issues)
            $totalAnalyses = DB::table('analyses')
                ->where('user_id', $userId)
                ->count();

            $uniqueLocations = DB::table('analyses')
                ->where('user_id', $userId)
                ->distinct('landmark_name')
                ->count('landmark_name');

            $avgConfidence = DB::table('analyses')
                ->where('user_id', $userId)
                ->avg('confidence') ?? 0;

            $stats = [
                'total_analyses' => $totalAnalyses,
                'unique_locations' => $uniqueLocations,
                'avg_confidence' => round($avgConfidence),
            ];

            // Get recent analyses
            $recent = DB::table('analyses')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->limit(6)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'landmark_name' => $item->landmark_name ?? 'Unknown',
                        'city' => $item->city ?? '',
                        'country' => $item->country ?? '',
                        'confidence' => $item->confidence ?? 0,
                        'image_path' => $item->image_path ?? null,
                    ];
                });

            // Get popular landmarks across all users
            $popular = DB::table('analyses')
                ->select('landmark_name', 'country')
                ->selectRaw('COUNT(*) as count')
                ->whereNotNull('landmark_name')
                ->groupBy('landmark_name', 'country')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'landmark_name' => $item->landmark_name ?? 'Unknown',
                        'country' => $item->country ?? '',
                        'count' => $item->count ?? 0,
                    ];
                });

            // Get user data
            $user = DB::table('users')
                ->where('id', $userId)
                ->first();

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'recent' => $recent,
                'popular' => $popular,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

        } catch (\Exception $e) {
            // Log the error for debugging
            \Log::error('Dashboard error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading dashboard data: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Alternative: Get dashboard data using Eloquent models
     * (Use this if you prefer Eloquent)
     */
    public function dashboardDataEloquent()
    {
        try {
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please login to view dashboard data'
                ], 401);
            }

            $user = auth()->user();
            
            $stats = [
                'total_analyses' => Analysis::where('user_id', $user->id)->count(),
                'unique_locations' => Analysis::where('user_id', $user->id)
                    ->distinct('landmark_name')
                    ->count('landmark_name'),
                'avg_confidence' => round(Analysis::where('user_id', $user->id)->avg('confidence') ?? 0),
            ];

            $recent = Analysis::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->limit(6)
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'landmark_name' => $item->landmark_name ?? 'Unknown',
                        'city' => $item->city ?? '',
                        'country' => $item->country ?? '',
                        'confidence' => $item->confidence ?? 0,
                        'image_path' => $item->image_path,
                    ];
                });

            $popular = Analysis::select('landmark_name', 'country')
                ->selectRaw('COUNT(*) as count')
                ->whereNotNull('landmark_name')
                ->groupBy('landmark_name', 'country')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'landmark_name' => $item->landmark_name ?? 'Unknown',
                        'country' => $item->country ?? '',
                        'count' => $item->count ?? 0,
                    ];
                });

            return response()->json([
                'success' => true,
                'stats' => $stats,
                'recent' => $recent,
                'popular' => $popular,
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            \Log::error('Dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading dashboard data: ' . $e->getMessage(),
            ], 500);
        }
    }
}