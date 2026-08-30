<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;

// ============================================
// WEB ROUTES (HTML Views Only)
// ============================================

// Auth Pages
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');

// Web POST routes (form submissions)
Route::post('/login', [AuthController::class, 'webLogin']);
Route::post('/register', [AuthController::class, 'webRegister']);
Route::post('/logout', [AuthController::class, 'webLogout']);

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

// ============================================
// PROTECTED ROUTES (Auth Required – Session)
// ============================================

Route::middleware(['auth'])->group(function () {
    // ------------------------------------------
    // HTML VIEWS
    // ------------------------------------------
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/history', [AnalysisController::class, 'history'])->name('analysis.history');

    // Dashboard data (JSON)
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');

    // ------------------------------------------
    // API ENDPOINTS (Session Auth)
    // ------------------------------------------
    Route::prefix('api')->group(function () {
        // ✅ ASYNC UPLOAD (RECOMMENDED)
        Route::post('/analyze/store', [AnalysisController::class, 'store'])->name('api.analyze.store');

        // ✅ STATUS POLLING
        Route::get('/analyze/{id}/status', [AnalysisController::class, 'status'])->name('api.analyze.status');

        // 📋 Get results
        Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');

        // 🗑️ Delete
        Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');

        // 🔄 Retry
        Route::post('/analyze/{id}/retry', [AnalysisController::class, 'retry'])->name('api.analyze.retry');

        // 🖼️ Fetch image from URL
        Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');

        // 🌍 Street View
        Route::get('/street-view', [AnalysisController::class, 'streetView'])->name('api.street-view');

        // 🧹 Clear cache
        Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');

        // 📊 History (API version)
        Route::get('/history', [AnalysisController::class, 'getHistory'])->name('api.history');

        // ⚡ SYNC (legacy – optional)
        // Route::post('/analyze', [AnalysisController::class, 'analyze'])->name('api.analyze');
    });
});

// ============================================
// DEBUG ROUTES (Safe to keep)
// ============================================

Route::get('/debug/test-api', function () {
    return response()->json(['success' => true, 'message' => 'API is working!']);
});

Route::get('/debug/db', function () {
    $tables = \DB::connection()->getSchemaBuilder()->getTableListing();
    return response()->json([
        'success' => true,
        'tables' => $tables,
        'users_exists' => \Schema::hasTable('users'),
        'analyses_exists' => \Schema::hasTable('analyses'),
    ]);
});

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

// ✅ Route to check the jobs table
Route::get('/debug-jobs', function () {
    try {
        $hasTable = \Schema::hasTable('jobs');
        $count = $hasTable ? \DB::table('jobs')->count() : 0;
        return response()->json([
            'table_exists' => $hasTable,
            'jobs_count' => $count,
            'queue_connection' => config('queue.default'),
        ]);
    } catch (\Exception $e) {
        return response()->json(['error' => $e->getMessage()]);
    }
});

// ✅ Temporary route to clear route and config caches (use once, then remove)
Route::get('/clear-caches', function () {
    try {
        \Artisan::call('route:clear');
        \Artisan::call('config:clear');
        \Artisan::call('cache:clear');
        \Artisan::call('view:clear');
        return '✅ All caches cleared.';
    } catch (\Exception $e) {
        return '❌ Error: ' . $e->getMessage();
    }
});