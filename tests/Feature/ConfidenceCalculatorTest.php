<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
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
