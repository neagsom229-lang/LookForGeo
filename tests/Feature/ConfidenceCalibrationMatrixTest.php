<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\OpenStreetMapService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Diagnostic matrix for the "famous landmark scored 0%" report.
 *
 * Prints inputs -> score using realistic structured outputs (no EXIF), then
 * locks the CURRENT scores so any future weight change is intentional.
 * This test does NOT change weights.
 */
class ConfidenceCalibrationMatrixTest extends TestCase
{
    private function makeResult(string $landmark, string $country, float $lat, float $lng, array $overrides = []): array
    {
        return array_merge([
            'landmark_name' => $landmark,
            'city' => 'New York',
            'country' => $country,
            'latitude' => $lat,
            'longitude' => $lng,
            'confidence' => 95,
            'reasoning' => 'Distinctive statue and harbour.',
            'visual_clues' => ['statue', 'harbour', 'torch', 'pedestal'],
            'candidates' => [
                ['location' => $landmark, 'probability' => 90],
                ['location' => 'Nearby viewpoint', 'probability' => 30],
            ],
            'coordinate_sources' => 1,
        ], $overrides);
    }

    public function test_matrix_of_realistic_outputs_and_scores(): void
    {
        $cases = [
            'Statue of Liberty / no EXIF, no resolution, reverse geo off' => [
                'data' => $this->makeResult('Statue of Liberty', 'United States', 40.6892, -74.0445),
                'meta' => [],
                'osm' => null,
                'reverse' => false,
                'expected' => 25,
            ],
            'Statue of Liberty / low-res 320px' => [
                'data' => $this->makeResult('Statue of Liberty', 'United States', 40.6892, -74.0445),
                'meta' => ['resolution' => ['width' => 320, 'height' => 240]],
                'osm' => null,
                'reverse' => false,
                'expected' => 15,
            ],
            'Statue of Liberty / OSM says Canada (mismatch) + low-res' => [
                'data' => $this->makeResult('Statue of Liberty', 'United States', 40.6892, -74.0445),
                'meta' => ['resolution' => ['width' => 320, 'height' => 240]],
                'osm' => ['country' => 'Canada'],
                'reverse' => true,
                'expected' => 0,
            ],
            'Statue of Liberty / real Nominatim address.country shape' => [
                'data' => $this->makeResult('Statue of Liberty', 'United States', 40.6892, -74.0445),
                'meta' => ['resolution' => ['width' => 1280, 'height' => 960]],
                'osm' => ['address' => ['country' => 'United States']],
                'reverse' => true,
                // Real Nominatim nests country under "address"; the current
                // reader only checks top-level keys, so this signal is lost.
                'expected' => 35,
            ],
            'Phnom Kulen / no EXIF, no OSM, good resolution' => [
                'data' => $this->makeResult('Phnom Kulen', 'Cambodia', 13.6844, 103.9586, ['city' => 'Siem Reap']),
                'meta' => ['resolution' => ['width' => 1280, 'height' => 960]],
                'osm' => null,
                'reverse' => false,
                'expected' => 35,
            ],
            'Phnom Kulen / tied candidates (-15) + low-res (-10)' => [
                'data' => $this->makeResult('Phnom Kulen', 'Cambodia', 13.6844, 103.9586, [
                    'city' => 'Siem Reap',
                    'candidates' => [
                        ['location' => 'Phnom Kulen', 'probability' => 60],
                        ['location' => 'Kbal Spean', 'probability' => 55],
                    ],
                ]),
                'meta' => ['resolution' => ['width' => 320, 'height' => 240]],
                'osm' => null,
                'reverse' => false,
                'expected' => 0,
            ],
        ];

        $rows = [];
        foreach ($cases as $label => $case) {
            config([
                'services.gemini.confidence_reverse_geocode' => $case['reverse'],
                'services.gemini.key' => 'AIzaSyFakeCalibKey1234567890',
            ]);
            Cache::flush();
            $osm = \Mockery::mock(OpenStreetMapService::class);
            $osm->shouldReceive('reverseGeocode')->andReturn($case['osm']);
            $calculator = new ConfidenceCalculator($osm);

            $out = $calculator->calculate($case['data'], $case['meta']);
            $signals = $out['confidence_signals'];
            $rows[] = [
                $label,
                (string) ($signals['exif_gps']['points'] ?? 0),
                (string) ($signals['country_consistency']['points'] ?? 0),
                (string) ($signals['visual_cues']['points'] ?? 0),
                (string) ($signals['resolution']['points'] ?? 0),
                (string) ($signals['candidate_gap']['points'] ?? 0),
                (string) ($signals['coordinate_sources']['points'] ?? 0),
                $out['confidence'].'%',
                $out['confidence'] === 0 ? 'ZERO with consistent answer' : '-',
            ];

            // Lock current behaviour; changing weights requires updating this
            // table and the DEPLOY_NOTES proposal first.
            $this->assertSame($case['expected'], $out['confidence'], $label);
        }

        $table = "| Input | EXIF | Country | Cues | Res | Gap | Coord | Score | Flag |\n|---|---|---|---|---|---|---|---|---|\n";
        foreach ($rows as $row) {
            $table .= '| '.implode(' | ', $row)." |\n";
        }
        fwrite(STDERR, "\n".$table."\n");
    }
}
