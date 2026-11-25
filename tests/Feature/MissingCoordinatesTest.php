<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\ConfidenceCalculator;
use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiPrompt;
use App\Services\GeminiService;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Production symptom: identification succeeds but coordinates are absent.
 * The UI must never be handed an invented Eiffel Tower location, a NaN
 * Street View URL, or a confidence of 0 for a place that was actually found.
 */
class MissingCoordinatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.key' => 'AIzaSyFakeMissingCoordsKey12345',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.fallback_model' => 'gemini-3.1-flash-lite',
            'services.gemini.retries' => 0,
            'services.gemini.hedge_after' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 0,
            'services.gemini.escalation_model' => '',
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => false,
            'services.nominatim.contact' => 'ops@example.org',
        ]);
        app(GeminiCircuitBreaker::class)->reset();
        RateLimiter::clear('nominatim_request');
    }

    private function analysisFor(User $user, array $result): GeoAnalysis
    {
        return GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Completed',
            'progress' => 100,
            'image_path' => 'analyses/sydney.jpg',
            'image_url' => '/storage/analyses/sydney.jpg',
            'result' => $result,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    private function geminiResponse(array $result): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode($result),
                ]]],
            ]],
        ];
    }

    private function foundLandmarkWithoutCoordinates(): array
    {
        return [
            'landmark_name' => 'Sydney Opera House',
            'city' => 'Sydney',
            'country' => 'Australia',
            'region' => 'New South Wales',
            'latitude' => null,
            'longitude' => null,
            'confidence' => 100,
            'reasoning' => 'Distinctive sail-shaped roof on Sydney Harbour.',
            'description' => 'The Sydney Opera House.',
            'vantage_point' => 'ground',
            'visual_clues' => ['sails', 'harbour'],
            'tags' => ['landmark'],
            'candidates' => [],
        ];
    }

    public function test_status_returns_numeric_coordinates_or_explicit_nulls(): void
    {
        $user = User::factory()->create();
        $numeric = $this->analysisFor($user, array_merge($this->foundLandmarkWithoutCoordinates(), [
            'latitude' => '48.8584',
            'longitude' => '2.2945',
            'coordinates_missing' => false,
        ]));

        $this->actingAs($user, 'web')->getJson('/api/analyze/'.$numeric->id.'/status')
            ->assertOk()
            ->assertJsonPath('data.latitude', 48.8584)
            ->assertJsonPath('data.longitude', 2.2945);

        $missing = $this->analysisFor($user, array_merge($this->foundLandmarkWithoutCoordinates(), [
            'latitude' => 'NaN',
            'longitude' => 'not-a-number',
            'coordinates_missing' => true,
        ]));

        $this->actingAs($user, 'web')->getJson('/api/analyze/'.$missing->id.'/status')
            ->assertOk()
            ->assertJsonPath('data.latitude', null)
            ->assertJsonPath('data.longitude', null)
            ->assertJsonPath('data.coordinates_missing', true);
    }

    public function test_forward_geocode_fills_missing_coordinates_and_reports_ok(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse($this->foundLandmarkWithoutCoordinates()), 200
            ),
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '-33.8568',
                'lon' => '151.2153',
                'display_name' => 'Sydney Opera House, Sydney, Australia',
            ]], 200),
        ]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertFalse((bool) ($result['is_error'] ?? false));
        $this->assertSame(-33.8568, $result['latitude']);
        $this->assertSame(151.2153, $result['longitude']);
        $this->assertSame('forward_geocode', $result['coordinate_source'] ?? null);
        $this->assertSame('ok', $result['forward_geocode_outcome'] ?? null);
    }

    public function test_forward_geocode_http_error_keeps_null_coordinates_and_does_not_fail(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse($this->foundLandmarkWithoutCoordinates()), 200
            ),
            'nominatim.openstreetmap.org/*' => Http::response([], 503),
        ]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertFalse((bool) ($result['is_error'] ?? false));
        $this->assertNull($result['latitude'] ?? null);
        $this->assertNull($result['longitude'] ?? null);
        $this->assertSame('http_error', $result['forward_geocode_outcome'] ?? null);
    }

    public function test_forward_geocode_empty_keeps_null_coordinates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse($this->foundLandmarkWithoutCoordinates()), 200
            ),
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertNull($result['latitude'] ?? null);
        $this->assertNull($result['longitude'] ?? null);
        $this->assertSame('empty', $result['forward_geocode_outcome'] ?? null);
    }

    public function test_forward_geocode_timeout_keeps_null_coordinates(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse($this->foundLandmarkWithoutCoordinates()), 200
            ),
            'nominatim.openstreetmap.org/*' => function () {
                throw new ConnectionException('timed out');
            },
        ]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertNull($result['latitude'] ?? null);
        $this->assertNull($result['longitude'] ?? null);
        $this->assertSame('timeout', $result['forward_geocode_outcome'] ?? null);
    }

    public function test_found_landmark_without_coordinates_is_capped_not_zero(): void
    {
        $calculated = app(ConfidenceCalculator::class)->calculate(
            $this->foundLandmarkWithoutCoordinates(), []
        );

        $this->assertTrue((bool) $calculated['coordinates_missing']);
        $this->assertSame(49, $calculated['confidence']);
    }

    /** Forward-geocoded coordinates came from the model's own name: no self-corroboration. */
    public function test_forward_geocoded_coordinates_earn_no_corroboration_points(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => true,
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([[
            'lat' => '-33.8568',
            'lon' => '151.2153',
            'address' => ['country_code' => 'au', 'country' => 'Australia'],
        ]], 200)]);

        $data = $this->foundLandmarkWithoutCoordinates();
        $data['latitude'] = -33.8568;
        $data['longitude'] = 151.2153;
        $data['coordinate_source'] = 'forward_geocode';
        $data['coordinate_sources'] = 2;
        $data['forward_geocode_outcome'] = 'ok';

        $calculated = app(ConfidenceCalculator::class)->calculate($data, []);

        $this->assertSame(0, $calculated['confidence_signals']['country_consistency']['points']);
        $this->assertSame(0, $calculated['confidence_signals']['coordinate_sources']['points']);
        $this->assertSame(0, $calculated['confidence_signals']['forward_geocode_distance']['points']);
    }

    /** A Nominatim hit in another country must be discarded, not accepted. */
    public function test_forward_geocode_rejects_a_country_mismatch(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(
                $this->geminiResponse($this->foundLandmarkWithoutCoordinates()), 200
            ),
            'nominatim.openstreetmap.org/*' => Http::response([[
                'lat' => '-33.8568',
                'lon' => '151.2153',
                'display_name' => 'Sydney Opera House, Auckland, New Zealand',
                'address' => ['country_code' => 'nz', 'country' => 'New Zealand'],
            ]], 200),
        ]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertNull($result['latitude'] ?? null);
        $this->assertNull($result['longitude'] ?? null);
        $this->assertSame('country_mismatch', $result['forward_geocode_outcome'] ?? null);
    }

    /** Nominatim outcome must be visible only in the admin health payload. */
    public function test_admin_health_exposes_nominatim_outcome_without_secrets(): void
    {
        config([
            'services.gemini.health_probe' => false,
            'services.health.admin_token' => 'admin-secret',
        ]);
        cache()->put('nominatim:last_outcome', [
            'outcome' => 'empty',
            'at' => '2026-11-25T00:00:00+00:00',
        ], 60);

        $admin = $this->getJson('/api/health', ['X-Admin-Token' => 'admin-secret'])
            ->assertOk()
            ->assertJsonPath('info.nominatim.last_outcome', 'empty');
        $this->assertStringNotContainsString('ops@example.org', (string) $admin->getContent());

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'operational']);
    }

    /** The prompt must ask for a best estimate when a place was identified. */
    public function test_prompt_requires_best_estimate_for_identified_landmark(): void
    {
        $prompt = app(GeminiPrompt::class)->buildUltimateGeoPrompt([]);

        $this->assertStringContainsString('best-estimate latitude and longitude', $prompt);
        $this->assertStringContainsString('null only when the place is genuinely unknown', $prompt);
    }

    /** Public health stays exactly {"status":...} (no token, no diagnostics). */
    public function test_public_health_is_exact_status_only(): void
    {
        config(['services.gemini.health_probe' => false]);

        $response = $this->getJson('/api/health')->assertOk();

        $response->assertExactJson(['status' => 'operational']);
        $this->assertSame(24, strlen((string) $response->getContent()));
    }

    /** storage:link must not emit "already exists" ERROR on every deploy. */
    public function test_entrypoint_storage_link_is_idempotent(): void
    {
        $script = (string) file_get_contents(base_path('entrypoint.sh'));

        $this->assertStringContainsString('if [ -L public/storage ]; then', $script);
        $this->assertStringContainsString('Storage link already exists; skipping.', $script);
        $this->assertStringNotContainsString('if ! php artisan storage:link; then', $script);
    }

    public function test_unknown_landmark_without_coordinates_stays_low(): void
    {
        $data = $this->foundLandmarkWithoutCoordinates();
        $data['landmark_name'] = 'Unknown Location';

        $calculated = app(ConfidenceCalculator::class)->calculate($data, []);

        $this->assertTrue((bool) $calculated['coordinates_missing']);
        $this->assertLessThanOrEqual(49, $calculated['confidence']);
    }
}
