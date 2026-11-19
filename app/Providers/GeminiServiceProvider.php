<?php

namespace App\Providers;

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiPrompt;
use App\Services\Gemini\GeminiResultEnhancer;
use App\Services\Gemini\GeminiResultValidator;
use App\Services\GeminiService;
use Illuminate\Support\ServiceProvider;

class GeminiServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(GeminiClient::class, function ($app) {
            return new GeminiClient;
        });

        $this->app->singleton(GeminiPrompt::class, function ($app) {
            return new GeminiPrompt;
        });

        $this->app->singleton(GeminiResultEnhancer::class, function ($app) {
            return new GeminiResultEnhancer;
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
