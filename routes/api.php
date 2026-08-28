<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandmarkController;
use App\Http\Controllers\TestController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\AnalysisController;

// ============================================================
// TEST ROUTES
// ============================================================
Route::get('/test-apis', [TestController::class, 'testAll']);
Route::get('/test-gemini', [GeminiController::class, 'test']);
Route::post('/gemini-chat', [GeminiController::class, 'chat']);

// ============================================================
// WEB LOGIN/REGISTER (With Session) - For Web Views
// ============================================================
Route::post('/web-login', [AuthController::class, 'webLogin']);
Route::post('/web-register', [AuthController::class, 'webRegister']);
Route::post('/web-logout', [AuthController::class, 'webLogout'])->middleware('auth');

// ============================================================
// API LOGIN/REGISTER (Stateless) - For API Clients
// ============================================================
Route::post('/register', [AuthController::class, 'apiRegister']);
Route::post('/login', [AuthController::class, 'apiLogin']);
Route::get('/verify/{token}', [AuthController::class, 'verifyEmail']);

// ============================================================
// CORE ANALYSIS - Free to use (No auth required)
// ============================================================
Route::post('/identify', [LandmarkController::class, 'identify']);
Route::post('/identify-gps', [LandmarkController::class, 'identifyGpsOnly']);
Route::post('/extract-exif', [LandmarkController::class, 'extractExif']);
Route::get('/nearby', [LandmarkController::class, 'nearby']);
Route::get('/search', [LandmarkController::class, 'search']);

// ============================================================
// ANALYSIS ROUTES (Protected by Authentication)
// ============================================================
Route::middleware(['auth:sanctum'])->group(function () {
    
    // ✅ ASYNC ANALYSIS - RECOMMENDED (Handles large files, background processing)
    Route::post('/analyze/store', [AnalysisController::class, 'store'])->name('api.analyze.store');
    
    // ✅ STATUS POLLING - Check progress of async analysis
    // Route::get('/analyze/status/{id}', [AnalysisController::class, 'status'])->name('api.analyze.status');
    
    // 📊 GET ALL ANALYSES - User's history
    Route::get('/analyze/history', [AnalysisController::class, 'history'])->name('api.analyze.history');
    
    // 🗑️ DELETE ANALYSIS
    Route::delete('/analyze/{id}', [AnalysisController::class, 'destroy'])->name('api.analyze.destroy');
    
    // 🔄 RETRY FAILED ANALYSIS
    // Route::post('/analyze/retry/{id}', [AnalysisController::class, 'retry'])->name('api.analyze.retry');
    
    // ⚡ SYNC ANALYSIS - Kept for backward compatibility (may timeout on large files)
    Route::post('/analyze/sync', [AnalysisController::class, 'analyze'])->name('api.analyze.sync');
    
    // 🖼️ FETCH IMAGE FROM URL
    Route::get('/fetch-image', [AnalysisController::class, 'fetchImage'])->name('api.fetch-image');
    
    // 📋 GET RESULTS BY ID
    Route::get('/results/{id}', [AnalysisController::class, 'getResults'])->name('api.results');
    
    // 🌍 STREET VIEW
    Route::get('/street-view', [AnalysisController::class, 'streetView'])->name('api.street-view');
    
    // 🗺️ MAPS EMBED
    Route::get('/maps-embed', [AnalysisController::class, 'mapsEmbed'])->name('api.maps-embed');
    
    // 🔄 SESSION DATA (Legacy support)
    Route::get('/analysis-data', [AnalysisController::class, 'getSessionData'])->name('api.analysis-data');
    
    // 🧹 CLEAR CACHE
    Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');
    
    // 👤 USER PROFILE
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'apiLogout']);
    
    // 📍 LANDMARK OPERATIONS
    Route::get('/landmarks/{id}', [LandmarkController::class, 'show']);
    Route::get('/my-landmarks', [LandmarkController::class, 'myLandmarks']);
    Route::get('/favorites', [LandmarkController::class, 'favorites']);
    Route::post('/favorites/{id}', [LandmarkController::class, 'toggleFavorite']);
    Route::post('/share/{id}', [LandmarkController::class, 'share']);
    Route::get('/export/{id}', [LandmarkController::class, 'export']);
});

