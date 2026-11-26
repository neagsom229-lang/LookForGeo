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
            'services.gemini.forward_geocode_missing' => false,
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
        Http::fake([
            '*wikidata.org/w/api.php*' => Http::response([
                'search' => [
                    ['id' => 'Q1128', 'label' => 'Statue of Liberty'],
                ],
            ], 200),
            '*wikidata.org/wiki/*' => Http::response([
                'entities' => [
                    'Q1128' => [
                        'id' => 'Q1128',
                        'type' => 'item',
                        'claims' => [
                            'P625' => [
                                'mainsnak' => ['datavalue' => ['value' => ['latitude' => 40.6893, 'longitude' => -74.0444, 'precision' => 1000]]],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

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

    /**
     * Model coordinates that disagree with the gazetteer by more than the
     * 5 km preference threshold: the model answer is KEPT (no silent override),
     * the disagreement is surfaced, precision is downgraded to approximate and
     * the uncertainty radius is widened to at least the disagreement distance.
     */
    public function test_enricher_records_gazetteer_disagreement_and_keeps_model_coordinates(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => true,
        ]);
        Http::fake([
            '*wikidata.org/w/api.php*' => Http::response([
                'search' => [
                    ['id' => 'Q1128', 'label' => 'Statue of Liberty'],
                ],
            ], 200),
            '*wikidata.org/wiki/*' => Http::response([
                'entities' => [
                    'Q1128' => [
                        'id' => 'Q1128',
                        'type' => 'item',
                        'claims' => [
                            'P625' => [
                                'mainsnak' => ['datavalue' => ['value' => ['latitude' => 40.85, 'longitude' => -73.95, 'precision' => 1000]]],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult());

        $this->assertSame('model', $result['coordinate_source']);
        $this->assertGreaterThan(5.0, (float) $result['coordinate_disagreement_km']);
        $this->assertSame(40.6892, (float) $result['latitude']);
        $this->assertSame(-74.0445, (float) $result['longitude']);
        $this->assertArrayHasKey('gazetteer_coordinates', $result);
        $this->assertSame('wikidata', $result['gazetteer_coordinates']['source']);
        $this->assertSame('approximate', $result['location_precision']);
        $this->assertGreaterThanOrEqual(
            (float) $result['coordinate_disagreement_km'] * 1000,
            (float) $result['uncertainty_radius_m']
        );
    }

    /**
     * Model coordinates inside the 5 km tolerance: no disagreement, precision
     * is approximate with the model uncertainty radius (never zero).
     */
    public function test_enricher_keeps_model_coordinates_when_gazetteer_agrees(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => true,
        ]);
        Http::fake([
            '*wikidata.org/w/api.php*' => Http::response([
                'search' => [
                    ['id' => 'Q1128', 'label' => 'Statue of Liberty'],
                ],
            ], 200),
            '*wikidata.org/wiki/*' => Http::response([
                'entities' => [
                    'Q1128' => [
                        'id' => 'Q1128',
                        'type' => 'item',
                        'claims' => [
                            'P625' => [
                                'mainsnak' => ['datavalue' => ['value' => ['latitude' => 40.6893, 'longitude' => -74.0444, 'precision' => 1000]]],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult());

        $this->assertSame('model', $result['coordinate_source']);
        $this->assertNull($result['coordinate_disagreement_km']);
        $this->assertSame('approximate', $result['location_precision']);
        $this->assertSame(5000.0, (float) $result['uncertainty_radius_m']);
    }
}
