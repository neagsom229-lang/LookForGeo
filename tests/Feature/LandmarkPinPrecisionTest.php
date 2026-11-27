<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiCircuitBreaker;
use App\Services\Gemini\GeminiResultEnhancer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Phase 2 - landmark pin precision.
 *
 * Production finding: "San Vigilio di Marebbe" (landmark_name == city, an
 * alpine landscape) got a precise pin at confidence 49, and a Royal Palace,
 * Phnom Penh answer landed ~1 km off the true pin.
 *
 * Rules under test:
 *  - area-level answers (landmark_name == city, settlement / region / natural
 *    feature) are "approximate" with an uncertainty radius >= 2000 m;
 *  - a same-country landmark-class (Wikidata P31 allowlist) gazetteer pin more
 *    than 300 m from the model estimate adopts the gazetteer point
 *    (coordinate_source=gazetteer, disagreement recorded, radius >= 100 m);
 *  - compound names ("A and B") resolve the first part and go approximate when
 *    the parts are > 1 km apart;
 *  - a down Wikidata must not fail the analysis.
 */
class LandmarkPinPrecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow();
        config([
            'services.gemini.key' => 'AIzaSyFakePinPrecisionKey12345',
            'services.gemini.model' => 'gemini-3.5-flash-lite',
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => true,
            'services.gemini.forward_geocode_missing' => true,
            'services.nominatim.contact' => 'ops@example.org',
        ]);
        app(GeminiCircuitBreaker::class)->reset();
        RateLimiter::clear('nominatim_request');
        RateLimiter::clear('wikidata_request');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function baseResult(array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => 'Royal Palace',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 60,
            'reasoning' => 'Royal palace complex with long galleries.',
            'visual_clues' => ['royal', 'palace', 'galleries', 'gates'],
            'candidates' => [
                ['location' => 'Royal Palace', 'probability' => 80],
                ['location' => 'Silver Pagoda', 'probability' => 25],
            ],
        ], $overrides);
    }

    private function fakeWikidata(string $entityId, string $label, string $classId, float $lat, float $lng, string $countryCode = 'KH'): void
    {
        $countryEntity = [
            'entities' => [
                'Q424' => [
                    'id' => 'Q424',
                    'type' => 'item',
                    'claims' => [
                        'P297' => [
                            [
                                'mainsnak' => [
                                    'datavalue' => [
                                        'value' => $countryCode,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $landmarkEntity = [
            'entities' => [
                $entityId => [
                    'id' => $entityId,
                    'type' => 'item',
                    'claims' => [
                        'P625' => [
                            [
                                'mainsnak' => [
                                    'datavalue' => [
                                        'value' => [
                                            'latitude' => $lat,
                                            'longitude' => $lng,
                                            'precision' => 1000,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'P17' => [
                            [
                                'mainsnak' => [
                                    'datavalue' => [
                                        'value' => [
                                            'entity-type' => 'item',
                                            'id' => 'Q424',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'P31' => [
                            [
                                'mainsnak' => [
                                    'datavalue' => [
                                        'value' => [
                                            'entity-type' => 'item',
                                            'id' => $classId,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://www.wikidata.org/w/api.php*' => Http::response([
                'search' => [
                    ['id' => $entityId, 'label' => $label],
                ],
            ], 200),
            'https://www.wikidata.org/wiki/Special/EntityData/Q424.json*' => Http::response($countryEntity, 200),
            'https://www.wikidata.org/wiki/Special/EntityData/'.$entityId.'.json*' => Http::response($landmarkEntity, 200),
        ]);
    }

    public function test_settlement_level_answer_is_approximate_area_not_landmark(): void
    {
        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'landmark_name' => 'San Vigilio di Marebbe',
            'city' => 'San Vigilio di Marebbe',
            'country' => 'Italy',
            'latitude' => 46.5362,
            'longitude' => 11.5355,
            'coordinate_source' => 'gazetteer',
            'confidence' => 49,
        ]));

        $this->assertSame('approximate', $result['location_precision']);
        $this->assertGreaterThanOrEqual(2000.0, (float) $result['uncertainty_radius_m']);
    }

    public function test_gazetteer_landmark_beyond_pin_tolerance_adopts_wikidata_point(): void
    {
        $this->fakeWikidata('Q420618', 'Royal Palace', 'Q53536964', 11.563680555556, 104.93111944444, 'KH');

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'landmark_name' => 'Royal Palace',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 62,
        ]));

        $this->assertSame('gazetteer', $result['coordinate_source']);
        $this->assertEqualsWithDelta(11.563680555556, (float) $result['latitude'], 0.000001);
        $this->assertEqualsWithDelta(104.93111944444, (float) $result['longitude'], 0.000001);
        $this->assertSame('Q53536964', $result['gazetteer_coordinates']['class_id'] ?? null);
        $this->assertGreaterThan(0.0, (float) $result['coordinate_disagreement_km']);
        $this->assertEqualsWithDelta(
            max(100.0, 0.87 * 1000),
            (float) $result['uncertainty_radius_m'],
            0.01
        );
        $this->assertSame('landmark', $result['location_precision']);
    }

    public function test_gazetteer_landmark_within_pin_tolerance_stays_landmark_grade(): void
    {
        $this->fakeWikidata('Q1108673', 'Independence Monument, Phnom Penh', 'Q4989906', 11.5564, 104.9281, 'KH');

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'landmark_name' => 'Independence Monument',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 70,
        ]));

        $this->assertSame('gazetteer', $result['coordinate_source']);
        $this->assertSame('landmark', $result['location_precision']);
        $this->assertNull($result['coordinate_disagreement_km']);
        $this->assertSame(100.0, (float) $result['uncertainty_radius_m']);
    }

    public function test_wikidata_down_keeps_model_answer_and_does_not_fail(): void
    {
        Http::fake([
            'https://www.wikidata.org/w/api.php*' => Http::response(['error' => 'service unavailable'], 500),
            'https://nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'landmark_name' => 'Royal Palace',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'latitude' => 11.5564,
            'longitude' => 104.9282,
            'confidence' => 55,
        ]));

        $this->assertSame('model', $result['coordinate_source']);
        $this->assertEqualsWithDelta(11.5564, (float) $result['latitude'], 0.000001);
        $this->assertSame('approximate', $result['location_precision']);
        $this->assertGreaterThanOrEqual(2000.0, (float) $result['uncertainty_radius_m']);
        $this->assertNotNull($result['forward_geocode_outcome']);
    }

    public function test_compound_landmark_parts_more_than_1km_apart_are_approximate(): void
    {
        Http::fake([
            'https://www.wikidata.org/w/api.php*' => function ($request) {
                // Hermetic test double: the 1 req / 5 s provider limit must not
                // throttle the several gazetteer lookups one test performs.
                RateLimiter::clear('wikidata_request');

                return Http::response(['search' => []], 200);
            },
            'https://nominatim.openstreetmap.org/*' => function ($request) {
                // Hermetic test double: the OSM rate limiter (1 req / 5 s) must
                // not throttle the several gazetteer lookups one test performs.
                RateLimiter::clear('nominatim_request');

                $q = (string) ($request['q'] ?? '');

                return str_contains($q, 'Monastery')
                    ? Http::response([['lat' => '13.41', 'lon' => '103.86', 'display_name' => 'Monastery, Siem Reap, Cambodia']], 200)
                    : Http::response([['lat' => '13.40', 'lon' => '103.86', 'display_name' => 'Temple, Siem Reap, Cambodia']], 200);
            },
        ]);

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult([
            'landmark_name' => 'Temple and Monastery',
            'city' => 'Siem Reap',
            'country' => 'Cambodia',
            'latitude' => 13.39,
            'longitude' => 103.85,
            'confidence' => 58,
            'reasoning' => 'Two temple structures.',
            'visual_clues' => ['temple', 'monastery'],
            'candidates' => [
                ['location' => 'Temple and Monastery', 'probability' => 70],
                ['location' => 'Angkor Wat', 'probability' => 20],
            ],
        ]));

        $this->assertSame('approximate', $result['location_precision']);
        $this->assertGreaterThanOrEqual(2000.0, (float) $result['uncertainty_radius_m']);
        $this->assertEqualsWithDelta(13.40, (float) $result['latitude'], 0.000001);
    }

    public function test_analytics_still_shape_complete_after_enrich(): void
    {
        $this->fakeWikidata('Q420618', 'Royal Palace', 'Q53536964', 11.563680555556, 104.93111944444, 'KH');

        $result = app(GeminiResultEnhancer::class)->enhance($this->baseResult());

        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'completed',
            'result' => $result,
        ]);

        $this->assertSame('completed', $analysis->status);
        $this->assertSame('Royal Palace', $analysis->result['landmark_name']);
        $this->assertArrayHasKey('location_precision', $analysis->result);
        $this->assertArrayHasKey('uncertainty_radius_m', $analysis->result);
    }
}
