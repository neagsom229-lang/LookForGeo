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
// PROTECTED WEB ROUTES (HTML Views Only)
// ============================================

Route::middleware(['auth'])->group(function () {
    // These return HTML pages (not JSON)
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/history', [AnalysisController::class, 'history'])->name('analysis.history');
    
    // Dashboard data (optional, returns JSON for the dashboard view)
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');
});

// ============================================
// DEBUG ROUTES (Ignore these - safe to keep)
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