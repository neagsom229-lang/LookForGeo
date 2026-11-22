<?php

namespace App\Providers;

use App\Logging\SecretLogProcessor;
use App\Services\GeocodingService;
use App\Services\LandmarkRecognitionService;
use App\Support\LocalDatabaseGuard;
use App\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Stable boot timestamp for /api/health (no request-time ambiguity).
        config(['app.started_at' => now()->toIso8601String()]);

        $this->app->singleton(GeocodingService::class);
        $this->app->singleton(LandmarkRecognitionService::class);
        $this->app->singleton(RequestContext::class);
    }

    /** Public for a focused test; called only in production boot. */
    public function warnIfNominatimContactMissing(): void
    {
        $contact = trim((string) config('services.nominatim.contact', ''));
        if ($contact === '') {
            Log::warning('NOMINATIM_CONTACT is empty: Nominatim geocoding may be rejected. Set a role contact address in Render.');
        }
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
            $this->warnIfNominatimContactMissing();
        }

        // Rate limiter (migrated from RouteServiceProvider for Laravel 11+)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Every upload can trigger a paid Gemini call: tighter per-user burst
        // limit on top of the daily quota in AnalysisCostGuard.
        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->user()?->id ?: $request->ip());
        });

        // Central log sanitizer: redact key=..., AIza... and AQ... from
        // every message + context array written to the default log channel.
        try {
            $logger = Log::driver();
            if (method_exists($logger->getLogger(), 'pushProcessor')) {
                $logger->pushProcessor(new SecretLogProcessor);
            }
        } catch (\Throwable $e) {
            // Never let log wiring break boot; call-site redaction still applies.
        }
    }
}
