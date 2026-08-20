<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Analysis;

class HomeController extends Controller
{
    public function index()
    {
        return view('home');
    }

    public function dashboardData()
    {
        try {
            // Only show data for logged-in users
            if (!auth()->check()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please login to view dashboard data'
                ], 401);
            }
            
            $query = Analysis::where('user_id', auth()->id());
            
            $stats = [
                'total_analyses' => $query->count(),
                'unique_locations' => $query->distinct('landmark_name')->count('landmark_name'),
                'avg_confidence' => round($query->avg('confidence') ?? 0),
            ];

            $recent = $query->orderBy('created_at', 'desc')
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

            $popular = Analysis::whereNotNull('landmark_name')
                ->select('landmark_name', 'country')
                ->selectRaw('COUNT(*) as count')
                ->groupBy('landmark_name', 'country')
                ->orderBy('count', 'desc')
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
                'user' => auth()->user(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}