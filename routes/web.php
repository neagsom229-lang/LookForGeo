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

// ✅ Web POST routes
Route::post('/login', [AuthController::class, 'webLogin']);
Route::post('/register', [AuthController::class, 'webRegister']);
Route::post('/logout', [AuthController::class, 'webLogout']);

// Home
Route::get('/', [HomeController::class, 'index'])->name('home');

// Protected Web Routes
Route::middleware(['auth'])->group(function () {
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');
});

// ============================================
// API ROUTES (Protected by Auth)
// ============================================

Route::prefix('api')->middleware(['auth'])->group(function () {
    // Analysis API - All require authentication
    Route::post('/analyze', [AnalysisController::class, 'analyze']);
    Route::get('/fetch-image', [AnalysisController::class, 'fetchImage']);
    Route::get('/results/{id}', [AnalysisController::class, 'getResults']);
    Route::get('/analysis-data', [AnalysisController::class, 'getSessionData']);
    Route::post('/clear-cache', [AnalysisController::class, 'clearCache']);
    Route::get('/street-view', [AnalysisController::class, 'streetView']);
});

// ============================================
// DEBUG
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
        
        $response = Http::timeout(30)->post($url, [
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

Route::get('/test-gd', function () {
    $gdInfo = [
        'gd_enabled' => extension_loaded('gd'),
        'gd_version' => function_exists('gd_info') ? gd_info()['GD Version'] ?? 'Unknown' : 'Not available',
        'functions' => [
            'imagecreatefromstring' => function_exists('imagecreatefromstring'),
            'imagejpeg' => function_exists('imagejpeg'),
            'imagepng' => function_exists('imagepng'),
        ],
    ];
    
    return response()->json($gdInfo);
});

Route::get('/test-landmarks', function () {
    try {
        $count = \App\Models\Landmark::count();
        $landmarks = \App\Models\Landmark::limit(5)->get();
        
        return response()->json([
            'success' => true,
            'count' => $count,
            'landmarks' => $landmarks
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

Route::get('/check-db', function () {
    try {
        // Check if landmarks table exists
        $hasTable = \Schema::hasTable('landmarks');
        
        if (!$hasTable) {
            return response()->json([
                'success' => false,
                'message' => 'Landmarks table does not exist'
            ]);
        }
        
        // Get count
        $count = \DB::table('landmarks')->count();
        
        // Get first 5 records
        $landmarks = \DB::table('landmarks')->limit(5)->get();
        
        return response()->json([
            'success' => true,
            'table_exists' => $hasTable,
            'count' => $count,
            'landmarks' => $landmarks
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});

Route::get('/db-status', function () {
    try {
        $tables = \DB::connection()->getSchemaBuilder()->getTableListing();
        $usersCount = \App\Models\User::count();
        $landmarksCount = \App\Models\Landmark::count();
        
        return response()->json([
            'success' => true,
            'tables' => $tables,
            'users_count' => $usersCount,
            'landmarks_count' => $landmarksCount,
            'tables_count' => count($tables)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
});
Route::get('/db-test', function () {
    try {
        // Test database connection
        $pdo = DB::connection()->getPdo();
        return response()->json([
            'status' => 'success',
            'message' => '✅ Database connected!',
            'driver' => DB::connection()->getDriverName(),
            'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => '❌ Database connection failed: ' . $e->getMessage(),
            'error_code' => $e->getCode()
        ], 500);
    }
});
Route::get('/run-migrations', function () {
    try {
        Artisan::call('migrate', ['--force' => true]);
        return '✅ Migrations completed successfully!';
    } catch (\Exception $e) {
        return '❌ Error: ' . $e->getMessage();
    }
});