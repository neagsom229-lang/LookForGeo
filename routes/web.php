<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;

// ============================================
// WEB ROUTES (With Session & CSRF)
// ============================================

// Auth Pages
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');

// Web POST routes
Route::post('/login', [AuthController::class, 'webLogin']);
Route::post('/register', [AuthController::class, 'webRegister']);
Route::post('/logout', [AuthController::class, 'webLogout']);

// Home
Route::get('/', [HomeController::class, 'index'])->name('home');

// ============================================
// PROTECTED WEB ROUTES (Auth Required)
// ============================================

Route::middleware(['auth'])->group(function () {
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/history', [AnalysisController::class, 'history'])->name('analysis.history');
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');

    // ============================================
    // API ROUTES (Protected by Auth) - Web routes
    // ============================================

    Route::prefix('api')->group(function () {
        // ✅ Async analysis (RECOMMENDED - handles large files)
        Route::post('/analyze', [AnalysisController::class, 'store'])->name('api.analyze');
        
        // ✅ Status polling
        Route::get('/analyze/{id}/status', [AnalysisController::class, 'status'])->name('api.analyze.status');
        
        // ✅ Get results
        Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');
        
        // ✅ History
        Route::get('/history', [AnalysisController::class, 'history'])->name('api.history');
        
        // ✅ Delete analysis
        Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');
        
        // ✅ Retry failed analysis
        Route::post('/analyze/{id}/retry', [AnalysisController::class, 'retry'])->name('api.analyze.retry');
        
        // ✅ Legacy sync (kept for compatibility)
        Route::post('/analyze-sync', [AnalysisController::class, 'analyze'])->name('api.analyze.sync');
        
        // ✅ Fetch image from URL
        Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');
        
        // ✅ Street view
        Route::get('/street-view', [AnalysisController::class, 'streetView'])->name('api.street-view');
        
        // ✅ Session data (legacy)
        Route::get('/analysis-data', [AnalysisController::class, 'getSessionData'])->name('api.analysis-data');
        Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');
    });
});

// ============================================
// DEBUG ROUTES
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