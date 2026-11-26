<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\OpenStreetMapService;
use Tests\TestCase;

/**
 * Reproducible fixture for the Golden Gate Bridge report: named landmark,
 * model coordinates, matching country, no EXIF. Runs the calculator twice
 * (coordinate_source=model and coordinate_source=forward_geocode) and prints
 * the per-signal breakdown when TEST_VERBOSE=1.
 */
class ConfidenceBreakdownFixtureTest extends TestCase
{
    private function fixture(array $overrides = []): array
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

    private function calculator(): ConfidenceCalculator
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

    public function test_golden_gate_breakdown_for_model_and_self_sourced_coordinates(): void
    {
        $calculator = $this->calculator();
        $metadata = ['resolution' => ['width' => 1600, 'height' => 1200]];

        $model = $calculator->calculate($this->fixture(), $metadata);
        $selfSourced = $calculator->calculate(
            $this->fixture(['coordinate_source' => 'forward_geocode']),
            $metadata
        );

        $this->assertSame(75, $model['confidence']);
        $this->assertSame(10, $model['confidence_signals']['forward_geocode_distance']['points']);
        $this->assertSame(49, $selfSourced['confidence']);
        $this->assertSame(0, $selfSourced['confidence_signals']['forward_geocode_distance']['points']);

        if (getenv('TEST_VERBOSE') === '1') {
            fwrite(STDERR, "\n".$this->table($model, 'coordinate_source=model'));
            fwrite(STDERR, "\n".$this->table($selfSourced, 'coordinate_source=forward_geocode'));
        }
    }

    private function table(array $result, string $label): string
    {
        $rows = "| Signal | Points | State | Detail |\n|---|---|---|---|\n";
        foreach ($result['confidence_signals'] as $name => $signal) {
            $detail = [];
            foreach ($signal as $key => $value) {
                if (in_array($key, ['points', 'state'], true)) {
                    continue;
                }
                $detail[] = $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (is_array($value) ? json_encode($value) : (string) $value));
            }
            $rows .= '| '.$name.' | '.($signal['points'] ?? 0).' | '.($signal['state'] ?? '-').' | '.implode(', ', $detail)." |\n";
        }

        $rows .= "\nScore: {$result['confidence']}% ({$result['confidence_level']}), "
            .'low_confidence='.($result['low_confidence'] ? 'true' : 'false')."\n";
        $rows .= 'Cap rules: MAX_SCORE=95, LOW_CONFIDENCE_BELOW=50, NO_COORDINATE_CAP=49, '
            ."IDENTIFIED_COORDINATE_FLOOR=49, FORWARD_GEOCODE_AGREE_KM=5, FORWARD_GEOCODE_POINTS=10\n";

        return "Fixture: {$label}\n{$rows}";
    }
}
