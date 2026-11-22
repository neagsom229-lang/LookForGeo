<?php

namespace Tests\Feature;

use App\Services\OpenStreetMapService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class ReverseGeocodeTest extends TestCase
{
    public function test_success_uses_identifying_user_agent_and_is_cached_briefly(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                'address' => ['country' => 'United States', 'country_code' => 'us'],
            ], 200),
        ]);

        $osm = new OpenStreetMapService;
        $first = $osm->reverseGeocode(40.6892, -74.0445);
        $second = $osm->reverseGeocode(40.6892, -74.0445);

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(
            implode(' ', $request->header('User-Agent')),
            'TraceGeo/1.0'
        ));
    }

    public function test_rate_limit_is_logged_with_category(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'too many'], 429)]);

        $result = (new OpenStreetMapService)->reverseGeocode(1.0, 2.0);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->with('Reverse geocode failed', Mockery::on(function ($context) {
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
        Log::shouldHaveReceived('warning')->with('Reverse geocode failed', Mockery::on(function ($context) {
            return ($context['category'] ?? null) === 'timeout';
        }));
    }

    public function test_bad_response_is_logged_with_category(): void
    {
        Log::spy();
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('<html>not json</html>', 200)]);

        $result = (new OpenStreetMapService)->reverseGeocode(5.0, 6.0);

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->with('Reverse geocode failed', Mockery::on(function ($context) {
            return ($context['category'] ?? null) === 'bad_response';
        }));
    }

    public function test_at_most_one_request_per_second(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['address' => []], 200)]);

        $osm = new OpenStreetMapService;
        $started = hrtime(true);
        $osm->reverseGeocode(10.0, 20.0);
        $osm->reverseGeocode(30.0, 40.0);
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;

        Http::assertSentCount(2);
        $this->assertGreaterThanOrEqual(0.9, $elapsed, 'second request must be throttled');
    }
}
