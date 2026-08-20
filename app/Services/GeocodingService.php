<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Geocoding service using OpenStreetMap Nominatim
 */
class GeocodingService
{
    private string $baseUrl = 'https://nominatim.openstreetmap.org/search';
    private int $cacheTtl = 86400; // 24 hours

    /**
     * Geocode a location name to coordinates
     */
    public function geocode(string $query, ?float $hintLat = null, ?float $hintLng = null): ?array
    {
        if (empty(trim($query))) {
            return null;
        }

        $cacheKey = 'geocode_' . md5($query . '_' . ($hintLat ?? '') . '_' . ($hintLng ?? ''));

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $params = [
                'q' => $query,
                'format' => 'json',
                'limit' => 3,
                'addressdetails' => 1,
                'accept-language' => 'en',
            ];

            if ($hintLat !== null && $hintLng !== null) {
                $params['lat'] = $hintLat;
                $params['lon'] = $hintLng;
                $params['viewbox'] = ($hintLng - 5) . ',' . ($hintLat + 5) . ',' . ($hintLng + 5) . ',' . ($hintLat - 5);
                $params['bounded'] = 1;
            }

            $response = Http::withHeaders([
                'User-Agent' => 'TraceGeo/1.0',
            ])->timeout(10)->get($this->baseUrl, $params);

            if (!$response->successful()) {
                Log::warning('GeocodingService: API request failed', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            if (empty($data)) {
                Log::info('GeocodingService: No results found', ['query' => $query]);
                return null;
            }

            // Find the best match
            $best = $this->findBestMatch($data, $query);

            if ($best) {
                $result = [
                    'lat' => (float) $best['lat'],
                    'lng' => (float) $best['lon'],
                    'display_name' => $best['display_name'] ?? $query,
                    'address' => $best['address'] ?? [],
                ];

                Cache::put($cacheKey, $result, now()->addSeconds($this->cacheTtl));
                Log::info('GeocodingService: Success', [
                    'query' => $query,
                    'coords' => $result['lat'] . ', ' . $result['lng'],
                ]);

                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('GeocodingService: Error: ' . $e->getMessage(), ['query' => $query]);
            return null;
        }
    }

    /**
     * Find the best match from geocoding results
     */
    private function findBestMatch(array $results, string $query): ?array
    {
        if (empty($results)) {
            return null;
        }

        // Return the first result with high confidence
        foreach ($results as $result) {
            $class = $result['class'] ?? '';
            $type = $result['type'] ?? '';

            // Prefer specific landmarks and places
            if (in_array($class, ['place', 'amenity', 'tourism', 'historic'])) {
                return $result;
            }
        }

        // Return the first result
        return $results[0];
    }

    /**
     * Calculate distance between two coordinates in kilometers
     */
    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }
}