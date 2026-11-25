<?php

namespace App\Providers;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiPrompt;
use App\Services\Gemini\GeminiResultEnhancer;
use App\Services\Gemini\GeminiResultValidator;
use App\Services\GeminiService;
use App\Services\OpenStreetMapService;
use Illuminate\Support\ServiceProvider;

class GeminiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(GeminiClient::class, function ($app) {
            return new GeminiClient($app->make(GeminiCircuitBreaker::class));
        });

        $this->app->singleton(GeminiPrompt::class, function ($app) {
            return new GeminiPrompt;
        });

        $this->app->singleton(ConfidenceCalculator::class, function ($app) {
            return new ConfidenceCalculator($app->make(OpenStreetMapService::class));
        });

        $this->app->singleton(GeminiResultEnhancer::class, function ($app) {
            return new GeminiResultEnhancer(
                $app->make(ConfidenceCalculator::class),
                $app->make(OpenStreetMapService::class)
            );
        });

        $this->app->singleton(GeminiResultValidator::class, function ($app) {
            return new GeminiResultValidator;
        });

        $this->app->singleton(GeminiService::class, function ($app) {
            return new GeminiService(
                $app->make(GeminiClient::class),
                $app->make(GeminiPrompt::class),
                $app->make(GeminiResultEnhancer::class),
                $app->make(GeminiResultValidator::class)
            );
        });
    }

    public function boot()
    {
        //
    }
}
