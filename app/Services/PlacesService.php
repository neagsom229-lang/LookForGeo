<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlacesService
{
    /**
     * Coordinates are rounded to this many decimal places (~11m grid at the
     * equator) before being used in a cache key. Real GPS/AI coordinates
     * carry far more decimal precision than that, and essentially never
     * repeat exactly — using raw floats as cache keys meant almost every
     * request generated a brand-new key, so the TTLs below were mostly
     * theoretical. Two requests within ~11m of each other now correctly
     * share a cache entry.
     */
    private const CACHE_COORD_PRECISION = 4;

    /**
     * A place within this many meters of the query point is almost
     * certainly the landmark itself, re-appearing in its own "nearby"
     * list (e.g. identifying "Kep Crab Market" and OSM also having a POI
     * node tagged "Kep Crab Market" at ~0m). Filtered out rather than
     * trying to match it by name, which would miss variant
     * spellings/languages and wouldn't require plumbing the landmark's own
     * name into this class at all.
     */
    private const SELF_MATCH_THRESHOLD_M = 25;

    /**
     * The public overpass-api.de instance is a single point of failure —
     * well known for being rate-limited or slow under load, with no
     * built-in fallback. Trying alternate community-run mirrors in order
     * means a nearby-places request only fails if ALL of them are down at
     * once, rather than going silently empty whenever the first one has a
     * bad day.
     */
    private const OVERPASS_ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.osm.ch/api/interpreter',
    ];

    private GeocodingService $geocoder;

    public function __construct(GeocodingService $geocoder)
    {
        $this->geocoder = $geocoder;
    }

    public function geocode(string $query): ?array
    {
        return $this->geocoder->geocode($query);
    }

    public function reverseGeocode(float $lat, float $lng): array
    {
        $cacheKey = 'reverse_osm_' . $this->coordCacheKey($lat, $lng);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->geocoder->throttleNominatim();

            $response = Http::withHeaders([
                'User-Agent' => config('app.name', 'TraceGeo') . '/1.0 (reverse geocoding)',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'jsonv2',
                'zoom' => 18,
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['display_name'])) {
                    $result = [
                        'name' => $data['display_name'],
                        'city' => $data['address']['city'] ?? $data['address']['town'] ?? $data['address']['village'] ?? null,
                        'country' => $data['address']['country'] ?? null,
                        'state' => $data['address']['state'] ?? null,
                    ];
                    Cache::put($cacheKey, $result, now()->addDays(7));

                    return $result;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PlacesService::reverseGeocode: ' . $e->getMessage());
        }

        return ['name' => null, 'city' => null, 'country' => null];
    }

    public function getNearby(float $lat, float $lng, int $radius = 500): array
    {
        $cacheKey = 'nearby_osm_' . $this->coordCacheKey($lat, $lng) . "_{$radius}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $overpassQuery = sprintf(
            '[out:json];(
                node["tourism"](around:%d,%f,%f);
                node["historic"](around:%d,%f,%f);
                node["leisure"](around:%d,%f,%f);
                node["amenity"](around:%d,%f,%f);
                way["tourism"](around:%d,%f,%f);
                way["historic"](around:%d,%f,%f);
            );out center;',
            $radius, $lat, $lng,
            $radius, $lat, $lng,
            $radius, $lat, $lng,
            $radius, $lat, $lng,
            $radius, $lat, $lng,
            $radius, $lat, $lng
        );

        $elements = $this->queryOverpass($overpassQuery);
        if ($elements === null) {
            // Every mirror failed — return empty rather than throwing, but
            // deliberately don't cache this outcome, so the next request
            // tries fresh instead of being stuck on a cached failure for
            // hours.
            return [];
        }

        $places = [];
        foreach ($elements as $el) {
            if (empty($el['tags']['name'])) {
                continue;
            }

            $placeLat = $el['lat'] ?? $el['center']['lat'] ?? null;
            $placeLng = $el['lon'] ?? $el['center']['lon'] ?? null;

            if ($placeLat === null || $placeLng === null) {
                continue;
            }

            $distance = round($this->haversine($lat, $lng, $placeLat, $placeLng));

            if ($distance < self::SELF_MATCH_THRESHOLD_M) {
                continue;
            }

            $places[] = [
                'name' => $el['tags']['name'],
                'type' => $el['tags']['tourism'] ?? $el['tags']['historic'] ?? $el['tags']['leisure'] ?? $el['tags']['amenity'] ?? 'point_of_interest',
                'distance' => $distance,
                'lat' => $placeLat,
                'lng' => $placeLng,
            ];
        }

        usort($places, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        $places = array_slice($places, 0, 20);

        Cache::put($cacheKey, $places, now()->addHours(24));

        return $places;
    }

    public function searchPlaces(string $query): array
    {
        $cacheKey = 'search_osm_' . md5(strtolower($query));
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $this->geocoder->throttleNominatim();

            $response = Http::withHeaders([
                'User-Agent' => config('app.name', 'TraceGeo') . '/1.0 (place search)',
            ])->timeout(10)->get('https://nominatim.openstreetmap.org/search', [
                'q' => $query,
                'format' => 'json',
                'limit' => 10,
            ]);

            if ($response->successful()) {
                $results = [];
                foreach ($response->json() as $item) {
                    $results[] = [
                        'name' => $item['display_name'],
                        'lat' => (float) $item['lat'],
                        'lng' => (float) $item['lon'],
                        'type' => $item['type'] ?? 'place',
                    ];
                }
                Cache::put($cacheKey, $results, now()->addDays(7));

                return $results;
            }
        } catch (\Throwable $e) {
            Log::warning('PlacesService::searchPlaces: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Tries each Overpass mirror in turn until one succeeds. Returns the
     * raw `elements` array, or null only if every mirror failed (as
     * opposed to `[]`, which means a mirror succeeded but found nothing —
     * that distinction matters so getNearby() knows whether to skip caching).
     */
    private function queryOverpass(string $query): ?array
    {
        foreach (self::OVERPASS_ENDPOINTS as $endpoint) {
            try {
                $response = Http::timeout(15)->post($endpoint, ['data' => $query]);

                if ($response->successful()) {
                    return $response->json('elements', []);
                }

                Log::warning('PlacesService: Overpass mirror returned non-2xx', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('PlacesService: Overpass mirror failed', [
                    'endpoint' => $endpoint,
                    'error' => $e->getMessage(),
                ]);
                // Try the next mirror.
            }
        }

        return null;
    }

    private function coordCacheKey(float $lat, float $lng): string
    {
        return round($lat, self::CACHE_COORD_PRECISION) . '_' . round($lng, self::CACHE_COORD_PRECISION);
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}