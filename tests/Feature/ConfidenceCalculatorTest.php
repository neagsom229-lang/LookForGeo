<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\OpenStreetMapService;
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

        $osm = \Mockery::mock(OpenStreetMapService::class);
        $osm->shouldReceive('reverseGeocode')->andReturn([
            'address' => ['country' => 'United States', 'country_code' => 'us'],
        ]);
        $osm->shouldReceive('forwardGeocode')->andReturn([
            'lat' => 37.8199,
            'lng' => -122.4783,
            'country_code' => 'us',
            'display_name' => 'Golden Gate Bridge, San Francisco, California, United States',
        ]);
        $osm->shouldReceive('lastOutcomeCategory')->andReturn('ok');

        return new ConfidenceCalculator($osm);
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
        $osm = \Mockery::mock(OpenStreetMapService::class);
        $osm->shouldReceive('reverseGeocode')->andReturn([
            'address' => ['country' => 'United States', 'country_code' => 'us'],
        ]);
        // Same name, same distance, but the geocode resolves to Canada.
        $osm->shouldReceive('forwardGeocode')->andReturn([
            'lat' => 37.8199,
            'lng' => -122.4783,
            'country_code' => 'ca',
        ]);
        $osm->shouldReceive('lastOutcomeCategory')->andReturn('ok');

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
            $calculator = new ConfidenceCalculator(\Mockery::mock(OpenStreetMapService::class));

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
}
