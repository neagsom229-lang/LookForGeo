<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandmarkController;
use App\Http\Controllers\TestController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\AnalysisController;
use App\Http\Controllers\Api\AnalysisApiController;
use Illuminate\Support\Facades\Route;

// ============================================================
// TEST ROUTES
// ============================================================
Route::get('/test-apis', [TestController::class, 'testAll']);
Route::get('/test-gemini', [GeminiController::class, 'test']);
Route::post('/gemini-chat', [GeminiController::class, 'chat']);

// ============================================================
// WEB LOGIN/REGISTER (Session) - For Web Views
// ============================================================
Route::post('/web-login', [AuthController::class, 'webLogin']);
Route::post('/web-register', [AuthController::class, 'webRegister']);
Route::post('/web-logout', [AuthController::class, 'webLogout'])->middleware('auth');

// ============================================================
// API LOGIN/REGISTER (Stateless) - For Mobile/3rd-party Clients
// ============================================================
Route::post('/register', [AuthController::class, 'apiRegister']);
Route::post('/login', [AuthController::class, 'apiLogin']); // ✅ public
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
// PUBLIC HEALTH CHECK (Works without any token!)
// ============================================================
Route::get('/health', [AnalysisApiController::class, 'health']);

// ============================================================
// PROTECTED API ROUTES (Token-based auth:sanctum)
// For mobile apps or external API clients.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // 👤 User profile
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'apiLogout']);

    // 📍 Landmark operations
    Route::get('/landmarks/{id}', [LandmarkController::class, 'show']);
    Route::get('/my-landmarks', [LandmarkController::class, 'myLandmarks']);
    Route::get('/favorites', [LandmarkController::class, 'favorites']);
    Route::post('/favorites/{id}', [LandmarkController::class, 'toggleFavorite']);
    Route::post('/share/{id}', [LandmarkController::class, 'share']);
    Route::get('/export/{id}', [LandmarkController::class, 'export']);

    // 🧹 Clear cache
    Route::post('/clear-cache', [AnalysisController::class, 'clearCache'])->name('api.clear-cache');

    // 🚀 TraceGeo Analytics API
    Route::get('/history', [AnalysisApiController::class, 'history']);
    Route::post('/analyze', [AnalysisApiController::class, 'analyze'])->middleware('throttle:60,1');
});