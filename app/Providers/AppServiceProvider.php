<?php

namespace App\Providers;

use App\Services\GeocodingService;
use App\Services\IpGeolocationService;
use App\Services\LandmarkRecognitionService;
use App\Services\PlacesService;
use App\Services\WeatherService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

 // <-- Add this import

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocodingService::class);
        $this->app->singleton(PlacesService::class);
        $this->app->singleton(LandmarkRecognitionService::class);
        $this->app->singleton(WeatherService::class);
        $this->app->singleton(IpGeolocationService::class);
    }

    public function boot(): void
    {
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
