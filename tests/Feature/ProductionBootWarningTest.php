<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ProductionBootWarningTest extends TestCase
{
    public function test_empty_nominatim_contact_logs_a_warning(): void
    {
        $log = Log::spy();
        config(['services.nominatim.contact' => '']);

        (new AppServiceProvider(app()))->warnIfNominatimContactMissing();

        $log->shouldHaveReceived('warning')->with(Mockery::on(function ($message) {
            return str_contains((string) $message, 'NOMINATIM_CONTACT is empty');
        }));
    }

    /** The warning is a deploy-level hint, not a per-request/artisan log flood. */
    public function test_empty_nominatim_contact_warns_only_once_until_cache_expires(): void
    {
        cache()->flush();
        $log = Log::spy();
        config(['services.nominatim.contact' => '']);

        $provider = new AppServiceProvider(app());
        $provider->warnIfNominatimContactMissing();
        $provider->warnIfNominatimContactMissing();

        $log->shouldHaveReceived('warning')->once()->with(Mockery::on(function ($message) {
            return str_contains((string) $message, 'NOMINATIM_CONTACT is empty');
        }));
    }

    public function test_configured_contact_does_not_warn(): void
    {
        $log = Log::spy();
        config(['services.nominatim.contact' => 'ops@example.org']);

        (new AppServiceProvider(app()))->warnIfNominatimContactMissing();

        $log->shouldNotHaveReceived('warning');
    }

    public function test_fallback_model_equal_to_primary_logs_a_warning(): void
    {
        $log = Log::spy();
        config([
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.fallback_model' => 'gemini-3.5-flash-lite',
        ]);

        (new AppServiceProvider(app()))->warnIfGeminiFallbackEqualsPrimary();

        $log->shouldHaveReceived('warning')->with(Mockery::on(function ($message) {
            return str_contains((string) $message, 'GEMINI_FALLBACK_MODEL equals GEMINI_MODEL');
        }));
    }

    /** An empty fallback config collapses to the primary, so it must warn too. */
    public function test_empty_fallback_model_collapses_to_primary_and_warns(): void
    {
        $log = Log::spy();
        config([
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.fallback_model' => '',
        ]);

        (new AppServiceProvider(app()))->warnIfGeminiFallbackEqualsPrimary();

        $log->shouldHaveReceived('warning')->with(Mockery::on(function ($message) {
            return str_contains((string) $message, 'GEMINI_FALLBACK_MODEL equals GEMINI_MODEL');
        }));
    }

    public function test_distinct_fallback_model_does_not_warn(): void
    {
        $log = Log::spy();
        config([
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.fallback_model' => 'gemini-3.1-flash-lite',
        ]);

        (new AppServiceProvider(app()))->warnIfGeminiFallbackEqualsPrimary();

        $log->shouldNotHaveReceived('warning');
    }
}
