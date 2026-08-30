<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application.
| All routes that return HTML views or handle session-based logic go here.
|
*/

// ============================================
// PUBLIC ROUTES (No Auth)
// ============================================

// Auth pages
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');

// Auth form submissions
Route::post('/login', [AuthController::class, 'webLogin']);
Route::post('/register', [AuthController::class, 'webRegister']);
Route::post('/logout', [AuthController::class, 'webLogout']);

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

// ============================================
// PROTECTED ROUTES (Requires Login)
// ============================================

Route::middleware(['auth'])->group(function () {

    // ---- HTML Views ----
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/history', [AnalysisController::class, 'history'])->name('analysis.history');

    // ---- Dashboard API (JSON) ----
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');

    // ---- API Endpoints (Session Auth) ----
    Route::prefix('api')->group(function () {
        // ✅ Async upload – returns immediately
        Route::post('/analyze/store', [AnalysisController::class, 'store'])->name('api.analyze.store');

        // ✅ Polling – checks status of background job
        Route::get('/analyze/{id}/status', [AnalysisController::class, 'status'])->name('api.analyze.status');

        // 📋 Get final result
        Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');

        // 🗑️ Delete an analysis
        Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');

        // 🔄 Retry a failed analysis
        Route::post('/analyze/{id}/retry', [AnalysisController::class, 'retry'])->name('api.analyze.retry');

        // 🖼️ Fetch image from external URL
        Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');

        // 🌍 Street View URL
        Route::get('/street-view', [AnalysisController::class, 'streetView'])->name('api.street-view');

        // 🧹 Clear cache (session-based)
        Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');

        // 📊 History (API version)
        Route::get('/history', [AnalysisController::class, 'getHistory'])->name('api.history');

        // ⚡ Legacy sync (optional – commented out)
        // Route::post('/analyze', [AnalysisController::class, 'analyze'])->name('api.analyze');
    });

    // ---- DEBUG ROUTES (Protected) ----
    // These are safe to keep in development, but you can remove them in production.

    // Check the jobs table (queue status)
    Route::get('/debug-jobs', function () {
        try {
            $hasTable = Schema::hasTable('jobs');
$count = $hasTable ? DB::table('jobs')->count() : 0;
            return response()->json([
                'table_exists' => $hasTable,
                'jobs_count' => $count,
                'queue_connection' => config('queue.default'),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

    // Check if a specific image file exists on disk
    Route::get('/debug-file/{filename}', function ($filename) {
        $path = storage_path('app/public/uploads/analyses/' . $filename);
        if (file_exists($path)) {
            return response()->file($path);
        }
        return response()->json(['error' => 'File not found'], 404);
    });
    Route::get('/test-queue', function () {
    $job = new \App\Jobs\AnalyzeImageJob(1); // replace with a real ID
    dispatch($job);
    return 'Job dispatched. Check worker logs.';
});
});

// ============================================
// PUBLIC DEBUG ROUTES (No Auth – use sparingly)
// ============================================

// Simple API health check
Route::get('/debug/test-api', function () {
    return response()->json(['success' => true, 'message' => 'API is working!']);
});

// Check database table existence (safe – no data returned)
Route::get('/debug/db', function () {
    $tables = DB::connection()->getSchemaBuilder()->getTableListing();
    return response()->json([
        'success' => true,
        'tables' => $tables,
        'users_exists' => Schema::hasTable('users'),
        'analyses_exists' => Schema::hasTable('analyses'),
    ]);
});

// Gemini API test (only shows key status, not the actual key)
Route::get('/test-gemini', function () {
    try {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model', 'gemini-3.6-flash');

        if (empty($apiKey)) {
            return response()->json([
                'error' => 'GEMINI_API_KEY is not set in .env file',
                'key_status' => 'missing'
            ], 400);
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        $response = \Illuminate\Support\Facades\Http::timeout(30)->post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'What is the capital of France? Reply with only the city name in JSON format: {"city": "Paris"}']
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.1,
                'maxOutputTokens' => 100,
            ]
        ]);

        return response()->json([
            'api_key_set' => !empty($apiKey),
            'model' => $model,
            'api_url' => $url,
            'status' => $response->status(),
            'response' => $response->json(),
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});