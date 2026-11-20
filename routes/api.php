<?php

use App\Http\Controllers\Api\AnalysisApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeminiController;
use App\Http\Controllers\LandmarkController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

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
Route::post('/login', [AuthController::class, 'apiLogin']); // public
Route::get('/verify/{token}', [AuthController::class, 'verifyEmail']);

// ============================================================
// CORE ANALYSIS - Authenticated only (cost-bearing)
// These endpoints reach Gemini / paid enrichment APIs. A web
// session OR a Sanctum bearer token is accepted. EncryptCookies
// and StartSession must run BEFORE auth.any so a session cookie
// is decrypted and loaded before the guard checks it.
// ============================================================
Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    'auth.any',
])->group(function () {
    Route::post('/identify', [LandmarkController::class, 'identify']);
    Route::post('/identify-gps', [LandmarkController::class, 'identifyGpsOnly']);
    Route::post('/extract-exif', [LandmarkController::class, 'extractExif']);
    Route::get('/nearby', [LandmarkController::class, 'nearby']);
    // The handler is searchPlace(); the old action name "search" did not exist.
    Route::get('/search', [LandmarkController::class, 'searchPlace']);
});

// ============================================================
// PUBLIC HEALTH CHECK (Works without any token!)
// ============================================================
Route::get('/health', [AnalysisApiController::class, 'health']);

// ============================================================
// PROTECTED API ROUTES (Token-based auth:sanctum)
// For mobile apps or external API clients.
// ============================================================
Route::middleware('auth:sanctum')->group(function () {
    // User profile
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/logout', [AuthController::class, 'apiLogout']);

    // Landmark operations
    Route::get('/landmarks/{id}', [LandmarkController::class, 'show']);
    Route::get('/my-landmarks', [LandmarkController::class, 'myLandmarks']);
    Route::get('/favorites', [LandmarkController::class, 'favorites']);
    Route::post('/favorites/{id}', [LandmarkController::class, 'toggleFavorite']);
    Route::post('/share/{id}', [LandmarkController::class, 'share']);
    Route::get('/export/{id}', [LandmarkController::class, 'export']);

    // Clear cache
    Route::post('/gemini-chat', [GeminiController::class, 'chat'])->middleware('throttle:10,1');

    // TraceGeo Analytics API
    Route::get('/history', [AnalysisApiController::class, 'history']);
    Route::post('/analyze', [AnalysisApiController::class, 'analyze'])->middleware('throttle:60,1');
});
