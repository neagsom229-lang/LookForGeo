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
        Log::spy();
        config(['services.nominatim.contact' => '']);

        (new AppServiceProvider(app()))->warnIfNominatimContactMissing();

        Log::shouldHaveReceived('warning')->with(Mockery::on(function ($message) {
            return str_contains((string) $message, 'NOMINATIM_CONTACT is empty');
        }));
    }

    public function test_configured_contact_does_not_warn(): void
    {
        Log::spy();
        config(['services.nominatim.contact' => 'ops@example.org']);

        (new AppServiceProvider(app()))->warnIfNominatimContactMissing();

        Log::shouldNotHaveReceived('warning');
    }
}
