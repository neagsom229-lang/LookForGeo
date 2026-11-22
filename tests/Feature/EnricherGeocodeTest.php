<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiResultEnhancer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Geocode signals must be exercised through the ENRICHER, not only the calculator. */
class EnricherGeocodeTest extends TestCase
{
    use RefreshDatabase;

    private function baseResult(array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => 'Statue of Liberty',
            'city' => 'New York',
            'country' => 'United States',
            'latitude' => 40.6892,
            'longitude' => -74.0445,
            'confidence' => 95,
            'reasoning' => 'Statue and harbour.',
            'visual_clues' => ['statue', 'harbour', 'torch', 'pedestal'],
            'candidates' => [
                ['location' => 'Statue of Liberty', 'probability' => 90],
                ['location' => 'Viewpoint', 'probability' => 30],
            ],
        ], $overrides);
    }

    private function realNominatimPayload(): array
    {
        return [
            'place_id' => 12345,
            'lat' => '40.6892470',
            'lon' => '-74.0445020',
            'display_name' => 'Statue of Liberty, New York, United States',
            'address' => [
                'house_number' => '1',
                'road' => 'Liberty Island',
                'city' => 'New York',
                'state' => 'New York',
                'country' => 'United States',
                'country_code' => 'us',
            ],
        ];
    }

    public function test_enricher_stores_country_signal_and_geocode_attempt(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => false,
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->realNominatimPayload(), 200)]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult(), [
            'resolution' => ['width' => 1600, 'height' => 1200],
        ]);

        $signal = $result['confidence_signals']['country_consistency'];
        $this->assertSame(20, $signal['points']);
        $this->assertSame('match', $signal['state']);
        $this->assertSame('us', $signal['reverse_code']);

        $attempt = $result['geocode_attempt'];
        $this->assertSame('ok', $attempt['outcome_category']);
        $this->assertSame([40.6892, -74.0445], $attempt['coordinates_used']);
        $this->assertSame('united states', $attempt['reverse_country']);
    }

    public function test_enricher_without_coordinates_records_skip(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => false,
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($this->realNominatimPayload(), 200)]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'latitude' => null,
            'longitude' => null,
        ]));

        $this->assertSame('skipped_no_coordinates', $result['geocode_attempt']['outcome_category']);
        $this->assertNull($result['geocode_attempt']['coordinates_used'][0]);
        $this->assertSame('unavailable', $result['confidence_signals']['country_consistency']['state']);
        Http::assertNothingSent();
    }

    public function test_enricher_records_rate_limit_outcome(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => false,
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response(['error' => 'slow down'], 429)]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult());

        $this->assertSame('rate_limit', $result['geocode_attempt']['outcome_category']);
        $this->assertSame('unavailable', $result['confidence_signals']['country_consistency']['state']);
    }

    public function test_forward_geocode_distance_is_informational_and_shown_by_explain(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => true,
        ]);
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '40.6893', 'lon' => '-74.0444', 'display_name' => 'Statue of Liberty, New York'],
        ], 200)]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult());
        $signal = $result['confidence_signals']['forward_geocode_distance'];

        $this->assertSame(0, $signal['points'], 'informational only until real-photo evidence');
        $this->assertSame('resolved', $signal['state']);
        $this->assertLessThan(1.0, (float) $signal['distance_km']);

        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'completed',
            'result' => $result,
        ]);
        $output = new BufferedOutput;
        Artisan::call('analyses:explain', ['id' => $analysis->id], $output);
        $text = $output->fetch();

        $this->assertStringContainsString('Geocode attempt:', $text);
        $this->assertStringContainsString('outcome category: disabled', $text);
        $this->assertStringContainsString('Forward-geocode distance:', $text);
        $this->assertStringContainsString('points=0', $text);
    }
}
