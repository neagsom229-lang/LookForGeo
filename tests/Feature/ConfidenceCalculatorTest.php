<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
use Tests\Support\FakeOpenStreetMapService;
use Tests\TestCase;

class ConfidenceCalculatorTest extends TestCase
{
    private function baseResult(array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => 'Eiffel Tower',
            'city' => 'Paris',
            'country' => 'France',
            'latitude' => 48.8584,
            'longitude' => 2.2945,
            'confidence' => 100,
            'visual_clues' => ['tower', 'rivets', 'seine', 'iron'],
            'candidates' => [
                ['location' => 'Eiffel Tower', 'probability' => 92],
                ['location' => 'Arc de Triomphe', 'probability' => 40],
                ['location' => 'Louvre', 'probability' => 20],
            ],
            'coordinate_sources' => 2,
        ], $overrides);
    }

    public function test_model_self_reported_confidence_is_never_the_score(): void
    {
        $result = app(ConfidenceCalculator::class)->calculate(
            $this->baseResult([
                'visual_clues' => [],
                'candidates' => [],
                'coordinate_sources' => 0,
                'latitude' => null,
                'longitude' => null,
            ]),
            []
        );

        $this->assertSame(100, $result['model_reported_confidence']);
        $this->assertLessThan(50, $result['confidence']);
        $this->assertNotSame(100, $result['confidence']);
        $this->assertTrue($result['low_confidence']);
        $this->assertSame('calibrated-signals', $result['confidence_basis']);
    }

    public function test_strong_checkable_signals_yield_high_but_capped_confidence(): void
    {
        $result = app(ConfidenceCalculator::class)->calculate(
            $this->baseResult(['matched_landmark' => 'Eiffel Tower']),
            [
                'gps' => ['latitude' => 48.8584, 'longitude' => 2.2945],
                'resolution' => ['width' => 1600, 'height' => 1200],
            ]
        );

        // 25 + 20 + 10 + 10 + 15 + 10 = 90, capped at 95 (never 100).
        $this->assertSame(90, $result['confidence']);
        $this->assertSame('Very High', $result['confidence_level']);
        $this->assertFalse($result['low_confidence']);
        $this->assertSame([], $result['alternatives']);
        $this->assertStringContainsString('EXIF GPS agrees', implode(' ', $result['confidence_factors']));
    }

    public function test_exif_conflict_reduces_confidence_and_explains_why(): void
    {
        $result = app(ConfidenceCalculator::class)->calculate(
            $this->baseResult(['matched_landmark' => 'Eiffel Tower']),
            [
                'gps' => ['latitude' => 40.7128, 'longitude' => -74.0060],
                'resolution' => ['width' => 1600, 'height' => 1200],
            ]
        );

        $this->assertSame('conflict', $result['confidence_signals']['exif_gps']['state']);
        $this->assertStringContainsString('EXIF GPS conflicts', implode(' ', $result['confidence_factors']));
        $this->assertLessThan(90, $result['confidence']);
    }

    /**
     * Golden Gate Bridge, model-supplied coordinates, independent forward
     * geocode agrees, country matches. No EXIF GPS.
     */
    private function goldenGateResult(array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => 'Golden Gate Bridge',
            'city' => 'San Francisco',
            'country' => 'United States',
            'latitude' => 37.8199,
            'longitude' => -122.4783,
            'confidence' => 20,
            'visual_clues' => ['suspension towers', 'international orange', 'main cables', 'bay'],
            'candidates' => [
                ['location' => 'Golden Gate Bridge', 'probability' => 88],
                ['location' => 'San Francisco-Oakland Bay Bridge', 'probability' => 30],
            ],
            'coordinate_sources' => 2,
            'coordinate_source' => 'model',
        ], $overrides);
    }

    private function calculatorWithGeocoders(): ConfidenceCalculator
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => true,
        ]);

        return new ConfidenceCalculator(new FakeOpenStreetMapService(
            ['address' => ['country' => 'United States', 'country_code' => 'us']],
            [
                'lat' => 37.8199,
                'lng' => -122.4783,
                'country_code' => 'us',
                'display_name' => 'Golden Gate Bridge, San Francisco, California, United States',
            ]
        ));
    }

    public function test_forward_geocode_agreement_awards_points_for_model_coordinates(): void
    {
        $result = $this->calculatorWithGeocoders()->calculate(
            $this->goldenGateResult(),
            ['resolution' => ['width' => 1600, 'height' => 1200]]
        );

        $signal = $result['confidence_signals']['forward_geocode_distance'];
        $this->assertSame(10, $signal['points'], 'model coordinates within 5 km + same country earn points');
        $this->assertSame('agree', $signal['state']);
        $this->assertTrue($signal['country_match']);
        $this->assertSame(75, $result['confidence']);
        $this->assertStringContainsString('agree with an independent forward geocode', implode(' ', $result['confidence_factors']));
    }

    public function test_forward_geocode_self_sourced_coordinates_earn_no_points(): void
    {
        $result = $this->calculatorWithGeocoders()->calculate(
            $this->goldenGateResult(['coordinate_source' => 'forward_geocode']),
            ['resolution' => ['width' => 1600, 'height' => 1200]]
        );

        $signal = $result['confidence_signals']['forward_geocode_distance'];
        $this->assertSame(0, $signal['points'], 'coordinates from the geocode itself are circular');
        $this->assertSame('self_sourced', $signal['state']);
        $this->assertSame('self_sourced', $result['confidence_signals']['country_consistency']['state']);
        $this->assertStringContainsString('circular', implode(' ', $result['confidence_factors']));
    }

    public function test_forward_geocode_in_another_country_earns_no_points(): void
    {
        config([
            'services.gemini.confidence_reverse_geocode' => true,
            'services.gemini.confidence_forward_geocode' => true,
        ]);
        // Same name, same distance, but the geocode resolves to Canada.
        $osm = new FakeOpenStreetMapService(
            ['address' => ['country' => 'United States', 'country_code' => 'us']],
            ['lat' => 37.8199, 'lng' => -122.4783, 'country_code' => 'ca']
        );

        $result = (new ConfidenceCalculator($osm))->calculate(
            $this->goldenGateResult(),
            ['resolution' => ['width' => 1600, 'height' => 1200]]
        );

        $signal = $result['confidence_signals']['forward_geocode_distance'];
        $this->assertSame(0, $signal['points']);
        $this->assertFalse($signal['country_match']);
    }

    /**
     * Monotonic property: for the same landmark, adding a valid pin must
     * never lower the score below the no-coordinate result (49 cap).
     */
    public function test_identified_landmark_with_coordinates_is_never_lower_than_without(): void
    {
        $lowRes = ['resolution' => ['width' => 320, 'height' => 240]];
        $variants = [
            'all negative signals' => [
                'overrides' => [
                    'visual_clues' => [],
                    'candidates' => [
                        ['location' => 'Golden Gate Bridge', 'probability' => 50],
                        ['location' => 'Bay Bridge', 'probability' => 48],
                    ],
                    'coordinate_sources' => 1,
                ],
                'meta' => $lowRes,
            ],
            'no evidence at all' => [
                'overrides' => [
                    'visual_clues' => [],
                    'candidates' => [],
                    'coordinate_sources' => 0,
                ],
                'meta' => $lowRes,
            ],
            'strong evidence with EXIF' => [
                'overrides' => [],
                'meta' => [
                    'gps' => ['latitude' => 37.8199, 'longitude' => -122.4783],
                    'resolution' => ['width' => 1600, 'height' => 1200],
                ],
            ],
        ];

        foreach ($variants as $label => $variant) {
            config([
                'services.gemini.confidence_reverse_geocode' => false,
                'services.gemini.confidence_forward_geocode' => false,
            ]);
            $calculator = new ConfidenceCalculator(new FakeOpenStreetMapService);

            $withCoords = $calculator->calculate(
                $this->goldenGateResult($variant['overrides']),
                $variant['meta']
            );
            $withoutCoords = $calculator->calculate(
                $this->goldenGateResult(array_merge($variant['overrides'], ['latitude' => null, 'longitude' => null])),
                $variant['meta']
            );

            $this->assertSame(49, $withoutCoords['confidence'], $label.' (without coordinates)');
            $this->assertGreaterThanOrEqual(
                $withoutCoords['confidence'],
                $withCoords['confidence'],
                $label.': valid coordinates must not score lower than missing coordinates'
            );
        }
    }

    public function test_low_confidence_returns_top_three_alternatives(): void
    {
        $result = app(ConfidenceCalculator::class)->calculate(
            $this->baseResult([
                'visual_clues' => [],
                'candidates' => [
                    ['location' => 'Low', 'probability' => 10],
                    ['location' => 'High', 'probability' => 90],
                    ['location' => 'Mid', 'probability' => 50],
                    ['location' => 'Fourth', 'probability' => 5],
                ],
            ]),
            ['resolution' => ['width' => 200, 'height' => 200]]
        );

        $this->assertTrue($result['low_confidence']);
        $this->assertCount(3, $result['alternatives']);
        $this->assertSame('High', $result['alternatives'][0]['location']);
        $this->assertSame('Mid', $result['alternatives'][1]['location']);
        $this->assertSame('Low', $result['alternatives'][2]['location']);
    }

    private function calculatorWithoutGeocoders(): ConfidenceCalculator
    {
        config([
            'services.gemini.confidence_reverse_geocode' => false,
            'services.gemini.confidence_forward_geocode' => false,
        ]);

        return new ConfidenceCalculator(new FakeOpenStreetMapService);
    }

    /**
     * The floor is a rule, not measured evidence. The result must say so.
     */
    public function test_floor_flag_is_exposed_when_the_identified_landmark_floor_applies(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->goldenGateResult([
                'visual_clues' => [],
                'candidates' => [],
                'coordinate_sources' => 1,
            ]),
            ['resolution' => ['width' => 320, 'height' => 240]]
        );

        $this->assertSame(49, $result['confidence']);
        $this->assertTrue($result['confidence_floor_applied']);
        $this->assertStringContainsString('score floored at 49', implode(' ', $result['confidence_factors']));
    }

    public function test_floor_flag_is_false_when_the_score_is_earned(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult(),
            ['resolution' => ['width' => 1600, 'height' => 1200], 'gps' => ['latitude' => 48.8584, 'longitude' => 2.2945]]
        );

        // 25 + 10 + 10 + 15 + 10 = 70 from the checkable signals (country is
        // not verifiable with the offline geocoders turned off here).
        $this->assertSame(70, $result['confidence']);
        $this->assertFalse($result['confidence_floor_applied']);
    }

    public function test_two_model_agreement_awards_points_for_same_name_and_nearby_coordinates(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'winner' => 'gemini-primary',
                'secondary_model_answer' => [
                    'landmark_name' => 'eiffel tower',
                    'latitude' => 48.8600,
                    'longitude' => 2.2945,
                    'model' => 'gemini-fallback',
                ],
            ]),
            []
        );

        $signal = $result['confidence_signals']['model_agreement'];
        $this->assertSame(10, $signal['points']);
        $this->assertSame('agree', $signal['state']);
        $this->assertTrue($signal['names_match']);
        $this->assertStringContainsString('Two models independently agreed', implode(' ', $result['confidence_factors']));
    }

    public function test_two_model_agreement_requires_the_same_landmark_name(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'winner' => 'gemini-primary',
                'secondary_model_answer' => [
                    'landmark_name' => 'Louvre Museum',
                    'latitude' => 48.8584,
                    'longitude' => 2.2945,
                    'model' => 'gemini-fallback',
                ],
            ]),
            []
        );

        $signal = $result['confidence_signals']['model_agreement'];
        $this->assertSame(0, $signal['points']);
        $this->assertFalse($signal['names_match']);
        $this->assertSame('disagree', $signal['state']);
    }

    public function test_two_model_agreement_requires_coordinates_within_five_km(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'winner' => 'gemini-primary',
                'secondary_model_answer' => [
                    'landmark_name' => 'Eiffel Tower',
                    // ~20 km north of the primary answer.
                    'latitude' => 49.0384,
                    'longitude' => 2.2945,
                    'model' => 'gemini-fallback',
                ],
            ]),
            []
        );

        $signal = $result['confidence_signals']['model_agreement'];
        $this->assertSame(0, $signal['points']);
        $this->assertTrue($signal['names_match']);
        $this->assertSame('disagree', $signal['state']);
        $this->assertGreaterThan(5, $signal['distance_km']);
    }

    public function test_two_model_agreement_is_not_awarded_to_the_same_model(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'winner' => 'gemini-primary',
                'secondary_model_answer' => [
                    'landmark_name' => 'Eiffel Tower',
                    'latitude' => 48.8584,
                    'longitude' => 2.2945,
                    'model' => 'gemini-primary',
                ],
            ]),
            []
        );

        $signal = $result['confidence_signals']['model_agreement'];
        $this->assertSame(0, $signal['points']);
        $this->assertSame('same_model', $signal['state']);
    }

    public function test_two_model_agreement_is_not_awarded_for_forward_geocoded_coordinates(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'winner' => 'gemini-primary',
                'secondary_model_answer' => [
                    'landmark_name' => 'Eiffel Tower',
                    'latitude' => 48.8584,
                    'longitude' => 2.2945,
                    'coordinate_source' => 'forward_geocode',
                    'model' => 'gemini-fallback',
                ],
            ]),
            []
        );

        $signal = $result['confidence_signals']['model_agreement'];
        $this->assertSame(0, $signal['points']);
        $this->assertSame('self_sourced', $signal['state']);
    }

    public function test_gazetteer_sourced_coordinates_earn_no_self_corroboration_points(): void
    {
        $result = $this->calculatorWithoutGeocoders()->calculate(
            $this->baseResult([
                'coordinate_source' => 'gazetteer',
                'coordinate_sources' => 1,
            ]),
            []
        );

        $this->assertSame(0, $result['confidence_signals']['country_consistency']['points']);
        $this->assertSame('self_sourced', $result['confidence_signals']['country_consistency']['state']);
        $this->assertSame(0, $result['confidence_signals']['forward_geocode_distance']['points']);
        $this->assertSame('self_sourced', $result['confidence_signals']['forward_geocode_distance']['state']);
        $this->assertSame(0, $result['confidence_signals']['coordinate_sources']['points']);
        $this->assertSame('self_sourced', $result['confidence_signals']['coordinate_sources']['state']);
    }

    /**
     * The kept-first escalation path uses this method: it must add the
     * agreement points without re-running any geocoder.
     */
    public function test_secondary_answer_can_be_applied_after_the_first_score(): void
    {
        $calculator = $this->calculatorWithoutGeocoders();
        $first = $calculator->calculate(
            $this->baseResult(),
            ['resolution' => ['width' => 1600, 'height' => 1200], 'gps' => ['latitude' => 48.8584, 'longitude' => 2.2945]]
        );

        $this->assertSame(70, $first['confidence']);
        $this->assertFalse($first['confidence_floor_applied']);

        $updated = $calculator->withSecondaryModelAnswer($first, [
            'landmark_name' => 'Eiffel Tower',
            'latitude' => 48.8584,
            'longitude' => 2.2945,
            'model' => 'gemini-fallback',
        ]);

        // 70 + MODEL_AGREEMENT_POINTS(10) = 80 (below the MAX_SCORE(95) cap).
        $this->assertSame(10, $updated['confidence_signals']['model_agreement']['points']);
        $this->assertSame(80, $updated['confidence']);
        $this->assertFalse($updated['confidence_floor_applied']);
    }
}
