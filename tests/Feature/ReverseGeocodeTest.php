<?php

namespace Tests\Feature;

use App\Services\OpenStreetMapService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class ReverseGeocodeTest extends TestCase
{
    public function test_success_uses_configured_contact_and_is_cached_briefly(): void
    {
        config(['services.nominatim.contact' => 'ops@example.org']);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['country' => 'United States', 'country_code' => 'us'],
            ], 200),
        ]);

        $osm = new OpenStreetMapService;
        $first = $osm->reverseGeocode(40.6892, -74.0445);
        $second = $osm->reverseGeocode(40.6892, -74.0445);

        $this->assertSame($first, $second);
        $this->assertSame('ok', $osm->lastOutcomeCategory());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(
            implode(' ', $request->header('User-Agent')),
            'contact: ops@example.org'
        ));
    }

    public function test_no_personal_contact_literal_is_committed(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/OpenStreetMapService.php'));

        $this->assertStringNotContainsString('admin@', $source);
        $this->assertStringNotContainsString('gmail.com', $source);
        $this->assertStringContainsString("config('services.nominatim.contact'", $source);
    }

    public function test_rate_limit_response_is_logged_with_category(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'too many'], 429)]);

        $result = (new OpenStreetMapService)->reverseGeocode(1.0, 2.0);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->with('Nominatim geocode failed', Mockery::on(function ($context) {
            return ($context['category'] ?? null) === 'rate_limit'
                && ($context['provider'] ?? null) === 'nominatim';
        }));
    }

    public function test_timeout_is_logged_with_category(): void
    {
        Log::spy();
        Http::fake(function () {
            throw new ConnectionException('cURL 28 timeout');
        });

        $result = (new OpenStreetMapService)->reverseGeocode(3.0, 4.0);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->with('Nominatim geocode failed', Mockery::on(function ($context) {
            return ($context['category'] ?? null) === 'timeout';
        }));
    }

    public function test_bad_response_is_logged_with_category(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('<html>not json</html>', 200)]);

        $result = (new OpenStreetMapService)->reverseGeocode(5.0, 6.0);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->with('Nominatim geocode failed', Mockery::on(function ($context) {
            return ($context['category'] ?? null) === 'bad_response';
        }));
    }

    public function test_cache_backed_limiter_blocks_second_request_without_sleeping(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => []], 200)]);

        $osm = new OpenStreetMapService;
        $started = hrtime(true);
        $osm->reverseGeocode(10.0, 20.0);
        $osm->reverseGeocode(30.0, 40.0);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        Http::assertSentCount(1);
        $this->assertLessThan(0.5, $elapsed, 'no in-process sleep');
        $this->assertSame('rate_limit', $osm->lastOutcomeCategory());
        $this->assertGreaterThanOrEqual(1, RateLimiter::attempts('nominatim_request'));
    }
}
