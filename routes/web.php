<?php

use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

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

// Fresh CSRF token for a browser retry after 419. Session middleware is
// required; no auth so a stale page can renew its token before retrying.
Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()])
        ->header('Cache-Control', 'no-store');
})->middleware('web');

// Homepage
Route::get('/', [HomeController::class, 'index'])->name('home');

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

    // Removed: Route::view('/how-it-works', 'how-it-works') referenced a view
    // that does not exist (only partials.how-it-works, included by the home
    // page, exists). The section remains reachable at '/'.

    // ---- API Endpoints (Session Auth) ----
    Route::prefix('api')->group(function () {
        Route::post('/analyze/store', [AnalysisController::class, 'store'])
            ->middleware('throttle:upload')
            ->name('api.analyze.store');
        Route::get('/analyze/{id}/status', [AnalysisController::class, 'status'])->name('api.analyze.status');
        Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');
        Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');
        Route::post('/analyze/{id}/retry', [AnalysisController::class, 'retry'])->name('api.analyze.retry');
        Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');
        // Removed in Phase C: /street-view and /clear-cache had no UI, test or
        // doc caller; their controller methods are deleted too.
        // NOTE: GET /api/history intentionally lives ONLY in routes/api.php
        // (Sanctum bearer clients). Defining it here as well silently
        // OVERWROTE the API route (RouteCollection assigns, it does not
        // append), which broke the documented token endpoint. See
        // tests/Feature/ApiContractTest.php.
    });

});
