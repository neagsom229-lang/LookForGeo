<?php

namespace App\Providers;

use App\Services\ElevationService;
use App\Services\GeocodingService;
use App\Services\IpGeolocationService;
use App\Services\LandmarkRecognitionService;
use App\Services\PlacesService;
use App\Services\SunService;
use App\Services\WeatherService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeocodingService::class);
        $this->app->singleton(PlacesService::class);
        $this->app->singleton(LandmarkRecognitionService::class);
        $this->app->singleton(WeatherService::class);
        $this->app->singleton(ElevationService::class);
        $this->app->singleton(SunService::class);
        $this->app->singleton(IpGeolocationService::class);
    }

    public function boot(): void {}
}