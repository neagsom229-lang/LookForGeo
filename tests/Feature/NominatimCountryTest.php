<?php

namespace Tests\Feature;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\OpenStreetMapService;
use Mockery;
use Tests\TestCase;

/** Real-shaped Nominatim payloads must compare by ISO 3166 alpha-2. */
class NominatimCountryTest extends TestCase
{
    private function score(string $statedCountry, ?array $nominatim, array $overrides = []): array
    {
        config(['services.gemini.confidence_reverse_geocode' => true]);
        $osm = Mockery::mock(OpenStreetMapService::class);
        $osm->shouldReceive('reverseGeocode')->andReturn($nominatim);

        $data = array_merge([
            'landmark_name' => 'Statue of Liberty',
            'city' => 'New York',
            'country' => $statedCountry,
            'latitude' => 40.6892,
            'longitude' => -74.0445,
            'confidence' => 95,
            'reasoning' => 'Statue and harbour.',
            'visual_clues' => ['statue', 'harbour', 'torch', 'pedestal'],
            'candidates' => [
                ['location' => 'Statue of Liberty', 'probability' => 90],
                ['location' => 'Viewpoint', 'probability' => 30],
            ],
            'coordinate_sources' => 1,
        ], $overrides);

        return (new ConfidenceCalculator($osm))->calculate($data, ['resolution' => ['width' => 1280, 'height' => 960]]);
    }

    public function test_real_nominatim_payload_matches_usa_aliases_by_iso_code(): void
    {
        $nominatim = [
            'place_id' => 123,
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

        foreach (['USA', 'United States', 'United States of America', 'us'] as $label) {
            $out = $this->score($label, $nominatim);
            $signal = $out['confidence_signals']['country_consistency'];

            $this->assertSame(20, $signal['points'], $label);
            $this->assertSame('match', $signal['state']);
            $this->assertSame('iso', $signal['method']);
            $this->assertSame('us', $signal['stated_code']);
            $this->assertSame('us', $signal['reverse_code']);
        }
    }

    public function test_uk_and_cambodia_aliases_match_by_iso_code(): void
    {
        foreach ([
            ['United Kingdom', 'gb', 'United Kingdom'],
            ['UK', 'gb', 'United Kingdom'],
            ['Cambodia', 'kh', 'Cambodia'],
        ] as [$stated, $code, $name]) {
            $out = $this->score($stated, [
                'address' => ['country' => $name, 'country_code' => $code],
            ]);
            $signal = $out['confidence_signals']['country_consistency'];

            $this->assertSame(20, $signal['points'], $stated);
            $this->assertSame('iso', $signal['method']);
        }
    }

    public function test_iso_mismatch_keeps_the_original_penalty(): void
    {
        $out = $this->score('United States', [
            'address' => ['country' => 'Canada', 'country_code' => 'ca'],
        ]);
        $signal = $out['confidence_signals']['country_consistency'];

        // Weights are unchanged: a genuine mismatch is still -20.
        $this->assertSame(-20, $signal['points']);
        $this->assertSame('mismatch', $signal['state']);
        $this->assertSame('iso', $signal['method']);
    }

    public function test_top_level_country_code_is_still_supported(): void
    {
        $out = $this->score('France', [
            'country' => 'France',
            'country_code' => 'fr',
        ]);
        $signal = $out['confidence_signals']['country_consistency'];

        $this->assertSame(20, $signal['points']);
        $this->assertSame('iso', $signal['method']);
    }

    /**
     * league/iso3166 resolves official names behind the alias map; 30 common
     * variants are locked here. Weights are unaffected by this lookup.
     */
    public function test_thirty_common_country_names_resolve_to_iso_alpha2(): void
    {
        $cases = [
            'Afghanistan' => 'af', 'Albania' => 'al', 'Algeria' => 'dz',
            'Argentina' => 'ar', 'Australia' => 'au', 'Austria' => 'at',
            'Bangladesh' => 'bd', 'Belgium' => 'be', 'Brazil' => 'br',
            'Bulgaria' => 'bg', 'Cambodia' => 'kh', 'Cameroon' => 'cm',
            'Canada' => 'ca', 'Chile' => 'cl', 'China' => 'cn',
            'Colombia' => 'co', 'Croatia' => 'hr', 'Denmark' => 'dk',
            'Egypt' => 'eg', 'Estonia' => 'ee', 'Ethiopia' => 'et',
            'Finland' => 'fi', 'France' => 'fr', 'Germany' => 'de',
            'Ghana' => 'gh', 'Greece' => 'gr', 'Hungary' => 'hu',
            'Iceland' => 'is', 'India' => 'in', 'Indonesia' => 'id',
        ];

        $out = $this->score('France', [
            'address' => ['country' => 'France', 'country_code' => 'fr'],
        ]);
        $this->assertSame(20, $out['confidence_signals']['country_consistency']['points']);

        // The normaliser is private; exercise it through the score signal by
        // asserting each name matches its own code via a reverse-geocode
        // payload that uses that name (no top-level country_code).
        foreach ($cases as $name => $code) {
            $out = $this->score($name, ['address' => ['country' => $name]]);
            $signal = $out['confidence_signals']['country_consistency'];

            $this->assertSame('iso', $signal['method'], $name);
            $this->assertSame($code, $signal['reverse_code'], $name);
            $this->assertSame(20, $signal['points'], $name);
        }
        $this->assertCount(30, $cases);
    }

    public function test_unmapped_name_falls_back_to_name_comparison(): void
    {
        $out = $this->score('Wakanda', [
            'address' => ['country' => 'Wakanda', 'country_code' => null],
        ]);
        $signal = $out['confidence_signals']['country_consistency'];

        $this->assertSame(20, $signal['points']);
        $this->assertSame('name', $signal['method']);
    }
}
