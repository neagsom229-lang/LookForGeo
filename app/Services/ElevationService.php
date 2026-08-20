<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElevationService
{
    public function getElevation(float $lat, float $lng): array
    {
        $cacheKey = "elevation_{$lat}_{$lng}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // Try Open-Elevation API (free, no key)
            $response = Http::timeout(5)->get('https://api.open-elevation.com/api/v1/lookup', [
                'locations' => "[{\"latitude\":{$lat},\"longitude\":{$lng}}]",
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['results'][0]['elevation'])) {
                    $elevation = $data['results'][0]['elevation'];
                    $result = [
                        'meters' => round($elevation),
                        'feet' => round($elevation * 3.28084),
                        'category' => $this->getElevationCategory($elevation),
                        'source' => 'open-elevation',
                    ];
                    Cache::put($cacheKey, $result, now()->addDays(7));
                    return $result;
                }
            }

            // Fallback: Try alternative free API
            $response = Http::timeout(5)->get('https://api.open-elevation.org/api/v1/lookup', [
                'locations' => "[{\"latitude\":{$lat},\"longitude\":{$lng}}]",
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data['results'][0]['elevation'])) {
                    $elevation = $data['results'][0]['elevation'];
                    $result = [
                        'meters' => round($elevation),
                        'feet' => round($elevation * 3.28084),
                        'category' => $this->getElevationCategory($elevation),
                        'source' => 'open-elevation.org',
                    ];
                    Cache::put($cacheKey, $result, now()->addDays(7));
                    return $result;
                }
            }

            // Second fallback: Use approximate elevation from terrain data
            $elevation = $this->estimateElevation($lat, $lng);
            if ($elevation !== null) {
                $result = [
                    'meters' => round($elevation),
                    'feet' => round($elevation * 3.28084),
                    'category' => $this->getElevationCategory($elevation),
                    'source' => 'estimated',
                ];
                Cache::put($cacheKey, $result, now()->addDays(1));
                return $result;
            }

            return ['error' => 'Elevation data unavailable'];
        } catch (\Throwable $e) {
            Log::warning('ElevationService: ' . $e->getMessage());
            
            // Last resort: estimate
            $elevation = $this->estimateElevation($lat, $lng);
            if ($elevation !== null) {
                return [
                    'meters' => round($elevation),
                    'feet' => round($elevation * 3.28084),
                    'category' => $this->getElevationCategory($elevation),
                    'source' => 'estimated',
                ];
            }

            return ['error' => 'Elevation data unavailable'];
        }
    }

    private function getElevationCategory(float $meters): string
    {
        if ($meters < 100) return 'lowland';
        if ($meters < 500) return 'hilly';
        if ($meters < 1500) return 'mountainous';
        if ($meters < 3000) return 'high_mountain';
        return 'very_high_altitude';
    }

    private function estimateElevation(float $lat, float $lng): ?float
    {
        // Simple approximation based on known regions
        // This is a fallback when APIs fail
        
        // Mountain regions
        $mountainRanges = [
            ['lat_min' => 45, 'lat_max' => 50, 'lng_min' => 6, 'lng_max' => 16, 'elevation' => 2000], // Alps
            ['lat_min' => 35, 'lat_max' => 45, 'lng_min' => -120, 'lng_max' => -110, 'elevation' => 2000], // Sierra Nevada
            ['lat_min' => 25, 'lat_max' => 35, 'lng_min' => -115, 'lng_max' => -100, 'elevation' => 1500], // Rocky Mountains
            ['lat_min' => 27, 'lat_max' => 30, 'lng_min' => 80, 'lng_max' => 90, 'elevation' => 3000], // Himalayas
            ['lat_min' => -30, 'lat_max' => -25, 'lng_min' => 145, 'lng_max' => 155, 'elevation' => 1000], // Australian Alps
        ];

        foreach ($mountainRanges as $range) {
            if ($lat >= $range['lat_min'] && $lat <= $range['lat_max'] &&
                $lng >= $range['lng_min'] && $lng <= $range['lng_max']) {
                return $range['elevation'];
            }
        }

        // Coastal areas
        if (abs($lat) < 10) return 50; // Tropics - low elevation
        if (abs($lat) < 30) return 200; // Subtropical
        if (abs($lat) < 50) return 300; // Temperate
        if (abs($lat) < 70) return 500; // Subarctic
        
        return 100; // Default
    }
}