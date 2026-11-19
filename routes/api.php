<?php

use App\Http\Controllers\Api\AnalysisApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\LandmarkController;
use App\Http\Controllers\TestController;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// ============================================================
// PUBLIC HEALTH CHECK (Works without any token!)
// ============================================================
Route::get('/health', [AnalysisApiController::class, 'health']);

// ============================================================
// AUTH ROUTES (Public)
// ============================================================
// Session-based login/register/logout live in routes/web.php
// (web routes with the 'web' middleware group).
// Mobile / 3rd-party clients use the stateless token flow below.
// ============================================================
// API (stateless) login/register/verify for mobile & 3rd-party clients
Route::post('/register', [AuthController::class, 'apiRegister']);
Route::post('/login', [AuthController::class, 'apiLogin']); // ✅ public
Route::get('/verify/{token}', [AuthController::class, 'verifyEmail']);

// ============================================================
// COST-BEARING CORE ANALYSIS — Authenticated + Throttled
// These endpoints reach Gemini (paid / quota-limited) and paid
// enrichment APIs, so they must not be callable without a login.
// auth.any accepts a web session OR a Sanctum bearer token.
// (The api middleware group already applies the 'api' rate limiter.)
// ============================================================
Route::middleware(['auth.any', StartSession::class])->group(function () {
    Route::post('/identify', [LandmarkController::class, 'identify']);
    Route::post('/identify-gps', [LandmarkController::class, 'identifyGpsOnly']);
    Route::post('/extract-exif', [LandmarkController::class, 'extractExif']);
    Route::get('/nearby', [LandmarkController::class, 'nearby']);
    Route::get('/search', [LandmarkController::class, 'search']);
});

// ============================================================
// DEBUG ROUTES — Authenticated (they probe paid APIs)
// ============================================================
Route::middleware(['auth.any', StartSession::class])->group(function () {
    Route::get('/test-apis', [TestController::class, 'testAll']);
});

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
    Route::post('/gemini-chat', [GeminiController::class, 'chat'])->middleware('throttle:10,1');

    // 🚀 TraceGeo Analytics API
    Route::get('/history', [AnalysisApiController::class, 'history']);
    Route::post('/analyze', [AnalysisApiController::class, 'analyze'])->middleware('throttle:60,1');
});
