<?php

namespace App\Services;

use App\Services\Gemini\SecretSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class OpenStreetMapService
{
    /** Nominatim usage policy: identify the app and respect 1 req/s. */
    private const NOMINATIM_CACHE_SECONDS = 600;

    private const NOMINATIM_LIMIT_KEY = 'nominatim_request';

    /** Outcome of the most recent geocode call, for explain/telemetry. */
    private ?string $lastOutcome = null;

    public function lastOutcomeCategory(): ?string
    {
        return $this->lastOutcome;
    }

    /** Contact comes from config/env; no personal address is committed. */
    private function nominatimUserAgent(): string
    {
        $contact = trim((string) config('services.nominatim.contact', ''));
        $url = trim((string) config('services.nominatim.app_url', 'https://lookforgeo.onrender.com'));

        return sprintf('TraceGeo/1.0 (%s; contact: %s)', $url, $contact !== '' ? $contact : 'unset');
    }

    /** Cache-backed limiter works across processes and workers (no sleep). */
    private function allowNominatim(string $operation): bool
    {
        if (RateLimiter::tooManyAttempts(self::NOMINATIM_LIMIT_KEY, 1)) {
            $this->lastOutcome = 'rate_limit';
            Log::warning('Nominatim request skipped', [
                'category' => 'rate_limit',
                'provider' => 'nominatim',
                'operation' => $operation,
            ]);

            return false;
        }
        RateLimiter::hit(self::NOMINATIM_LIMIT_KEY, 1);

        return true;
    }

    /**
     * Geocode with multiple fallback sources
     */
    public function geocode(string $location): ?array
    {
        // Try OpenStreetMap first
        $result = $this->geocodeOSM($location);
        if ($result) {
            return $result;
        }

        // Try Google Maps (if key available)
        $result = $this->geocodeGoogle($location);
        if ($result) {
            return $result;
        }

        // Try fallback: search with country
        $countries = ['Cambodia', 'Thailand', 'Vietnam', 'Laos', 'Myanmar', 'China', 'Japan', 'India'];
        foreach ($countries as $country) {
            if (strpos($location, $country) === false) {
                $result = $this->geocodeOSM($location.', '.$country);
                if ($result) {
                    return $result;
                }
            }
        }

        // Try with common suffixes
        $suffixes = [' Temple', ' Pagoda', ' Market', ' Monument', ' Palace', ' Museum'];
        foreach ($suffixes as $suffix) {
            if (strpos($location, $suffix) === false) {
                $result = $this->geocodeOSM($location.$suffix);
                if ($result) {
                    return $result;
                }
            }
        }

        return null;
    }

    /**
     * Geocode using OpenStreetMap Nominatim
     */
    protected function geocodeOSM(string $location): ?array
    {
        try {
            $cacheKey = 'osm_geocode_'.md5($location);

            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
            if (! $this->allowNominatim('geocode')) {
                return null;
            }

            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $location,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1,
                    'extratags' => 1,
                    'namedetails' => 1,
                    'countrycodes' => 'kh,th,vn,la,mm,cn,jp,in',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data)) {
                    $result = [
                        'lat' => (float) $data[0]['lat'],
                        'lng' => (float) $data[0]['lon'],
                        'display_name' => $data[0]['display_name'] ?? '',
                        'type' => $data[0]['type'] ?? '',
                        'class' => $data[0]['class'] ?? '',
                        'address' => $data[0]['address'] ?? [],
                        'source' => 'openstreetmap',
                    ];

                    Cache::put($cacheKey, $result, 86400);

                    return $result;
                }
            }
        } catch (\Exception $e) {
            Log::warning('OSM geocoding failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Geocode using Google Maps API (fallback)
     */
    protected function geocodeGoogle(string $location): ?array
    {
        $apiKey = config('services.google.maps_key');
        if (empty($apiKey)) {
            return null;
        }

        try {
            $cacheKey = 'google_geocode_'.md5($location);

            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $response = Http::timeout(10)
                ->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'address' => $location,
                    'key' => $apiKey,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if ($data['status'] === 'OK' && ! empty($data['results'])) {
                    $result = [
                        'lat' => $data['results'][0]['geometry']['location']['lat'],
                        'lng' => $data['results'][0]['geometry']['location']['lng'],
                        'display_name' => $data['results'][0]['formatted_address'] ?? '',
                        'source' => 'google_maps',
                    ];

                    Cache::put($cacheKey, $result, 86400);

                    return $result;
                }
            }
        } catch (\Exception $e) {
            Log::warning('Google geocoding failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Get nearby attractions with enhanced filtering
     */
    public function getNearbyAttractions(float $lat, float $lng, int $radius = 1500, int $limit = 10): array
    {
        try {
            $cacheKey = 'nearby_'.md5($lat.$lng.$radius.$limit);

            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $query = '[out:json];(';
            $query .= "node(around:$radius,$lat,$lng)[tourism];";
            $query .= "node(around:$radius,$lat,$lng)[historic];";
            $query .= "node(around:$radius,$lat,$lng)[amenity];";
            $query .= "node(around:$radius,$lat,$lng)[leisure];";
            $query .= "node(around:$radius,$lat,$lng)[shop];";
            $query .= "node(around:$radius,$lat,$lng)[information];";
            $query .= "way(around:$radius,$lat,$lng)[tourism];";
            $query .= "way(around:$radius,$lat,$lng)[historic];";
            $query .= "way(around:$radius,$lat,$lng)[amenity];";
            $query .= ');out body limit '.($limit * 2).';';
            $query .= 'out skel qt;';

            $response = Http::timeout(30)
                ->withHeaders(['User-Agent' => 'LandmarkApp/1.0'])
                ->get('https://overpass-api.de/api/interpreter', [
                    'data' => $query,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $places = [];
                $seen = [];

                foreach ($data['elements'] ?? [] as $element) {
                    $tags = $element['tags'] ?? [];
                    $name = $tags['name'] ?? $tags['name:en'] ?? '';

                    if (empty($name) || in_array($name, $seen)) {
                        continue;
                    }

                    $seen[] = $name;

                    $place = [
                        'name' => $name,
                        'type' => $tags['tourism'] ?? $tags['historic'] ?? $tags['amenity'] ?? $tags['leisure'] ?? $tags['shop'] ?? 'Point of Interest',
                        'distance' => $this->calculateDistance($lat, $lng, $element['lat'] ?? 0, $element['lon'] ?? 0),
                        'lat' => $element['lat'] ?? null,
                        'lng' => $element['lon'] ?? null,
                        'website' => $tags['website'] ?? null,
                        'opening_hours' => $tags['opening_hours'] ?? null,
                        'phone' => $tags['phone'] ?? null,
                        'wikipedia' => $tags['wikipedia'] ?? null,
                        'description' => $tags['description'] ?? $tags['note'] ?? null,
                    ];
                    $places[] = $place;
                }

                // Sort by distance
                usort($places, function ($a, $b) {
                    return ($a['distance'] ?? 99999) <=> ($b['distance'] ?? 99999);
                });

                $places = array_slice($places, 0, $limit);

                Cache::put($cacheKey, $places, 3600);

                return $places;
            }
        } catch (\Exception $e) {
            Log::warning('Nearby attractions query failed: '.$e->getMessage());
        }

        return [];
    }

    /**
     * Calculate distance between two coordinates (Haversine formula)
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get reverse geocoding
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        $cacheKey = 'reverse_'.md5($lat.$lng);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return is_array($cached) ? $cached : null;
        }

        try {
            if (! $this->allowNominatim('reverse')) {
                return null;
            }
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                    'addressdetails' => 1,
                    'zoom' => 18,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! is_array($data)) {
                    $this->logNominatimFailure('reverse', 'bad_response', ['status' => $response->status()]);

                    return null;
                }
                // "Briefly": avoid hammering a free service while still
                // refreshing stale country data.
                Cache::put($cacheKey, $data, self::NOMINATIM_CACHE_SECONDS);
                $this->lastOutcome = 'ok';

                return $data;
            }

            $this->logNominatimFailure('reverse',
                $response->status() === 429 ? 'rate_limit' : 'bad_response',
                ['status' => $response->status()]
            );
        } catch (ConnectionException $e) {
            $this->logNominatimFailure('reverse', 'timeout', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        } catch (\Throwable $e) {
            $this->logNominatimFailure('reverse', 'bad_response', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        }

        return null;
    }

    private function logNominatimFailure(string $operation, string $category, array $context): void
    {
        $this->lastOutcome = $category;
        Log::warning('Nominatim geocode failed', array_merge([
            'category' => $category,
            'provider' => 'nominatim',
            'operation' => $operation,
        ], $context));
    }

    /**
     * Search for places with category filter
     */
    /**
     * Forward geocode a place name for an informational distance signal.
     * Returns lat/lng plus the display name, or null on any failure.
     */
    public function forwardGeocode(string $landmark, ?string $city = null, ?string $country = null): ?array
    {
        $query = trim(implode(', ', array_filter([
            $landmark, $city, $country,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
        if ($query === '') {
            $this->lastOutcome = 'skipped_no_query';

            return null;
        }

        $cacheKey = 'forward_'.md5($query);
        if (Cache::has($cacheKey)) {
            $this->lastOutcome = 'ok';

            return Cache::get($cacheKey);
        }
        if (! $this->allowNominatim('forward')) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                $this->logNominatimFailure('forward', $response->status() === 429 ? 'rate_limit' : 'bad_response', [
                    'status' => $response->status(),
                ]);

                return null;
            }
            $data = $response->json();
            if (! is_array($data) || ! isset($data[0]['lat'], $data[0]['lon'])
                || ! is_numeric($data[0]['lat']) || ! is_numeric($data[0]['lon'])) {
                $this->logNominatimFailure('forward', 'bad_response', ['status' => $response->status()]);

                return null;
            }

            $result = [
                'lat' => (float) $data[0]['lat'],
                'lng' => (float) $data[0]['lon'],
                'display_name' => (string) ($data[0]['display_name'] ?? ''),
                'query' => $query,
            ];
            Cache::put($cacheKey, $result, self::NOMINATIM_CACHE_SECONDS);
            $this->lastOutcome = 'ok';

            return $result;
        } catch (ConnectionException $e) {
            $this->logNominatimFailure('forward', 'timeout', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        } catch (\Throwable $e) {
            $this->logNominatimFailure('forward', 'bad_response', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        }

        return null;
    }

    public function searchPlaces(string $query, string $category = ''): array
    {
        try {
            $cacheKey = 'search_'.md5($query.$category);

            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
            if (! $this->allowNominatim('search')) {
                return [];
            }

            $response = Http::timeout(10)
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query.($category ? ' '.$category : ''),
                    'format' => 'json',
                    'limit' => 5,
                    'addressdetails' => 1,
                ]);

            if ($response->successful()) {
                $data = $response->json();

                if (! is_array($data)) {
                    Log::warning('Place search failed: unexpected response shape');

                    return [];
                }

                $results = [];
                foreach ($data as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $lat = $item['lat'] ?? null;
                    $lon = $item['lon'] ?? null;
                    if (! is_numeric($lat) || ! is_numeric($lon)) {
                        // Nominatim can return entries without coordinates; skip them
                        // rather than blowing up the whole search.
                        continue;
                    }
                    $results[] = [
                        'name' => $item['display_name'] ?? '',
                        'lat' => (float) $lat,
                        'lng' => (float) $lon,
                        'type' => $item['type'] ?? '',
                        'class' => $item['class'] ?? '',
                    ];
                }
                Cache::put($cacheKey, $results, 3600);

                return $results;
            }
        } catch (\Exception $e) {
            Log::warning('Place search failed: '.$e->getMessage());
        }

        return [];
    }
}
