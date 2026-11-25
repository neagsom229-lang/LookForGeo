<?php

namespace App\Providers;

use App\Logging\FatalErrorHandler;
use App\Logging\SecretLogProcessor;
use App\Services\GeocodingService;
use App\Services\LandmarkRecognitionService;
use App\Support\LocalDatabaseGuard;
use App\Support\RequestContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Throwable;

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
        if ($contact !== '') {
            return;
        }

        // A boot-time warning must never break the app (Larastan boots the
        // console kernel with CACHE_STORE=database but no cache table).
        try {
            $warn = Cache::add('nominatim:contact_warning', true, now()->addDay());
        } catch (Throwable $e) {
            $warn = false;
        }
        if ($warn) {
            // Once per deploy/day, not on every request or artisan invocation.
            // Admin health also exposes contact_configured for diagnosis.
            Log::warning('NOMINATIM_CONTACT is empty: Nominatim geocoding may be rejected. Set a role contact address in Render.');
        }
    }

    /**
     * An empty fallback collapses to the primary model (never send '' as a
     * model name), so an empty or equal GEMINI_FALLBACK_MODEL means a 429/503
     * on the primary can never fail over -- the job just keeps hitting the
     * same rate limit. Warn loudly at startup instead of failing silently.
     *
     * Public for a focused test; called only in production boot.
     */
    public function warnIfGeminiFallbackEqualsPrimary(): void
    {
        $primary = trim((string) config('services.gemini.model', ''));
        $fallback = trim((string) config('services.gemini.fallback_model', ''));
        $effectiveFallback = $fallback === '' ? $primary : $fallback;

        if ($primary !== '' && $effectiveFallback === $primary) {
            Log::warning('GEMINI_FALLBACK_MODEL equals GEMINI_MODEL ('.$primary.'): a 429/503 on the primary can never fail over. Set a distinct GEMINI_FALLBACK_MODEL in Render.');
        }
    }

    public function boot(): void
    {
        try {
            $this->bootSafely();
        } catch (Throwable $e) {
            // Provider boot must never break artisan, queue workers or
            // Larastan's console-kernel bootstrap.
            try {
                Log::warning('app_provider_boot_failed', ['error' => get_class($e)]);
            } catch (Throwable $ignored) {
                // Logging itself is best-effort here.
            }
        }
    }

    private function bootSafely(): void
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
            // Console boot (artisan, queue worker, Larastan) must not run
            // warnings that read cache/database during bootstrap.
            if (! $this->app->runningInConsole()) {
                $this->warnIfNominatimContactMissing();
                $this->warnIfGeminiFallbackEqualsPrimary();
            }
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
        } catch (Throwable $e) {
            // Never let log wiring break boot; call-site redaction still applies.
        }

        // Register the fatal-error shutdown handler — one JSON line to stderr,
        // never throws, no container or Log facade dependency.
        FatalErrorHandler::register();
    }
}
