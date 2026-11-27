<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiResultEnhancer;
use App\Services\OpenStreetMapService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class GazetteerBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'AIzaSyFakeBudgetKey12345',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => true,
            'services.gemini.forward_geocode_missing' => true,
            'services.nominatim.contact' => 'ops@example.org',
        ]);

        app(GeminiCircuitBreaker::class)->reset();
        RateLimiter::clear('nominatim_request');
        RateLimiter::clear('wikidata_request');
        Cache::flush();
    }

    public function test_gazetteer_budget_timeout_keeps_model_pin_marks_approximate_and_records_timeout(): void
    {
        $now = 1000.0;
        $osm = app(OpenStreetMapService::class);
        $osm->setClock(function () use (&$now) {
            return $now;
        });
        $this->app->instance(OpenStreetMapService::class, $osm);

        Http::fake([
            'https://www.wikidata.org/w/api.php*' => function () use (&$now) {
                $now += 9.0; // exceed 8s budget

                return Http::response(['search' => []], 200);
            },
            'https://nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $result = app(GeminiResultEnhancer::class)->enhance([
            'landmark_name' => 'Royal Palace',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 60,
            'reasoning' => 'x',
            'visual_clues' => ['a'],
            'candidates' => [['location' => 'Royal Palace', 'probability' => 80]],
        ]);

        $this->assertSame('model', $result['coordinate_source'] ?? 'model');
        $this->assertEqualsWithDelta(11.5564, (float) $result['latitude'], 0.000001);
        $this->assertEqualsWithDelta(104.9282, (float) $result['longitude'], 0.000001);
        $this->assertSame('approximate', $result['location_precision']);
        $this->assertSame('timeout', $result['forward_geocode_outcome']);
    }

    public function test_wikidata_requests_send_descriptive_user_agent_with_contact(): void
    {
        $capturedUa = null;

        Http::fake([
            'https://www.wikidata.org/w/api.php*' => function ($request) use (&$capturedUa) {
                $capturedUa = $request->header('User-Agent')[0] ?? null;

                return Http::response(['search' => []], 200);
            },
            'https://nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        app(OpenStreetMapService::class)->forwardGeocode('Royal Palace', 'Phnom Penh', 'Cambodia');

        $this->assertNotNull($capturedUa);
        $this->assertStringContainsString('TraceGeo/1.0', $capturedUa);
        $this->assertStringContainsString('contact: ops@example.org', $capturedUa);
        $this->assertStringContainsString((string) config('services.nominatim.app_url'), $capturedUa);
    }

    public function test_admin_health_reports_wikidata_outcome(): void
    {
        Http::fake([
            'https://www.wikidata.org/w/api.php*' => Http::response(['search' => []], 200),
            'https://nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        app(OpenStreetMapService::class)->forwardGeocode('Royal Palace', 'Phnom Penh', 'Cambodia');

        $health = app(SystemHealthService::class)->readiness();
        $wikidata = $health['info']['wikidata'] ?? null;

        $this->assertIsArray($wikidata);
        $this->assertArrayHasKey('last_outcome', $wikidata);
        $this->assertArrayHasKey('last_outcome_at', $wikidata);
        $this->assertArrayHasKey('contact_configured', $wikidata);
        $this->assertContains($wikidata['last_outcome'], ['ok', 'empty', 'http_error', 'timeout', 'blocked', null]);
    }

    public function test_similarity_matches_transliterated_and_city_suffixed_names(): void
    {
        $osm = app(OpenStreetMapService::class);
        $ref = new \ReflectionClass($osm);

        $normalize = $ref->getMethod('normalizeLandmarkName');
        $normalize->setAccessible(true);
        $matches = $ref->getMethod('wikidataNameMatches');
        $matches->setAccessible(true);

        $this->assertSame('wat sampov meas', $normalize->invoke($osm, 'Wat Sampov Meas'));
        $this->assertSame('wat sampov meas temple', $normalize->invoke($osm, 'Wat Sampov Meas Temple'));

        $this->assertTrue($matches->invoke($osm, 'Wat Sampov Meas', [
            'labels' => ['en' => ['value' => 'Wat Sampov Meas Temple']],
            'aliases' => ['en' => []],
        ], 'Wat Sampov Meas Temple'));

        $this->assertTrue($matches->invoke($osm, 'Royal Palace', [
            'labels' => ['en' => ['value' => 'Royal Palace, Phnom Penh']],
            'aliases' => ['en' => []],
        ], 'Royal Palace, Phnom Penh'));
    }
}
