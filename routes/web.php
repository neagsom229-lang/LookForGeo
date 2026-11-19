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
        Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('web.clear-cache');
        Route::get('/history', [AnalysisController::class, 'getHistory'])->name('api.history');
    });

});
