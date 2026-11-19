<?php

namespace Tests\Feature;

use App\Services\OpenStreetMapService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchPlacesTest extends TestCase
{
    /**
     * Malformed Nominatim payloads (missing lat/lon, non-array rows, nonsense
     * body) must not throw or warn — valid entries are returned, junk is skipped.
     */
    public function test_search_places_skips_malformed_items(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                ['display_name' => 'Eiffel Tower, Paris', 'lat' => '48.8584', 'lon' => '2.2945', 'type' => 'tower', 'class' => 'tourism'],
                ['display_name' => 'Malformed row without coordinates'],
                'total garbage row',
                ['display_name' => 'Missing lon', 'lat' => '1.0'],
                ['display_name' => 'Non-numeric lat', 'lat' => 'not-a-number', 'lon' => '2.2945'],
            ], 200),
        ]);

        $results = (new OpenStreetMapService)->searchPlaces('eiffel tower');

        $this->assertCount(1, $results);
        $this->assertSame('Eiffel Tower, Paris', $results[0]['name']);
        $this->assertSame(48.8584, $results[0]['lat']);
        $this->assertSame(2.2945, $results[0]['lng']);
    }

    public function test_search_places_non_array_response_returns_empty(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response('<html>rate limited</html>', 200),
        ]);

        $this->assertSame([], (new OpenStreetMapService)->searchPlaces('eiffel tower'));
    }

    public function test_search_places_valid_response_is_parsed(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/search*' => Http::response([
                ['display_name' => 'Louvre Museum', 'lat' => '48.8606', 'lon' => '2.3376', 'type' => 'museum', 'class' => 'tourism'],
            ], 200),
        ]);

        $results = (new OpenStreetMapService)->searchPlaces('louvre');

        $this->assertCount(1, $results);
        $this->assertSame('Louvre Museum', $results[0]['name']);
        $this->assertSame(48.8606, $results[0]['lat']);
        $this->assertSame('museum', $results[0]['type']);
    }
}
