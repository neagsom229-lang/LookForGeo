<?php

namespace App\Providers;

use App\Services\GeocodingService;
use App\Services\LandmarkRecognitionService;
use App\Support\LocalDatabaseGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocodingService::class);
        $this->app->singleton(LandmarkRecognitionService::class);
    }

    public function boot(): void
    {
        // Local-only safety net: a local .env pointing at the production
        // database (pgsql / render.com host) must fail loudly at boot.
        if ($this->app->environment('local')) {
            $driver = (string) config('database.default', '');
            LocalDatabaseGuard::assert(
                $driver,
                (array) config('database.connections.'.$driver, [])
            );
        }

        // Force HTTPS in production to fix mixed content warnings
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Rate limiter (migrated from RouteServiceProvider for Laravel 11+)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });
    }
}
