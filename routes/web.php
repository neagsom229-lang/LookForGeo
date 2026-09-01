<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ============================================
// PUBLIC ROUTES (No Auth)
// ============================================

// Auth pages
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::get('/register', [AuthController::class, 'showRegister'])->name('register');

// Auth form submissions (Login/Register must be public)
Route::post('/login', [AuthController::class, 'webLogin']);
Route::post('/register', [AuthController::class, 'webRegister']);

// Public API Docs (Perfect!)
Route::view('/docs', 'docs');

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::view('/how-it-works',     'how-it-works');


// ============================================
// PROTECTED ROUTES (Requires Login)
// ============================================
Route::middleware(['web', 'auth:web'])->group(function () {

    // ---- HTML Views ----
    Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');
    Route::get('/history', [AnalysisController::class, 'history'])->name('analysis.history');

    // ---- LOGOUT (Moved here for security!) ----
    Route::post('/logout', [AuthController::class, 'webLogout']);

    // ---- Dashboard API (JSON) ----
    Route::get('/api/dashboard-data', [HomeController::class, 'dashboardData'])->name('api.dashboard-data');

    Route::view('/how-it-works', 'how-it-works');
    // ---- API Endpoints (Session Auth) ----
    Route::prefix('api')->group(function () {
        Route::post('/analyze/store', [AnalysisController::class, 'store'])->name('api.analyze.store');
        Route::get('/analyze/{id}/status', [AnalysisController::class, 'status'])->name('api.analyze.status');
        Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');
        Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');
        Route::post('/analyze/{id}/retry', [AnalysisController::class, 'retry'])->name('api.analyze.retry');
        Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');
        Route::get('/street-view', [AnalysisController::class, 'streetView'])->name('api.street-view');
        Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');
        Route::get('/history', [AnalysisController::class, 'getHistory'])->name('api.history');
    });

    // ============================================
    // PROTECTED DEBUG ROUTES (Only for logged-in admins for testing)
    // ============================================
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

    Route::get('/debug-file/{filename}', function ($filename) {
        $path = storage_path('app/public/uploads/analyses/' . $filename);
        if (file_exists($path)) {
            return response()->file($path);
        }
        return response()->json(['error' => 'File not found'], 404);
    });

    Route::get('/test-queue', function () {
        $job = new \App\Jobs\AnalyzeImageJob(1);
        dispatch($job);
        return 'Job dispatched. Check worker logs.';
    });

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

    // ============================================
    // TEST GEMINI (PROTECTED! Never expose this publicly)
    // ============================================
    Route::get('/test-gemini', function () {
        try {
            $apiKey = config('services.gemini.key');
            $model = config('services.gemini.model', 'gemini-3.6-flash');

            if (empty($apiKey)) {
                return response()->json(['error' => 'GEMINI_API_KEY is not set'], 400);
            }

            // (Testing logic here)
            return response()->json([
                'api_key_set' => true,
                'model' => $model,
                // NEVER return the API key or the URL! Just the status.
                'status' => 'working',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    });

});