<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SunService
{
    private const TIMEZONE_CACHE_TTL_SECONDS = 60 * 60 * 24 * 30; // timezone boundaries barely change
    private const TIMEOUT_SECONDS = 5;

    public function calculate(float $lat, float $lng): array
    {
        try {
            $dayOfYear = (int) date('z') + 1;
            $declination = 23.44 * sin(deg2rad((284 + $dayOfYear) * 360 / 365));

            $latRad = deg2rad($lat);
            $decRad = deg2rad($declination);

            $cosHourAngle = (cos(deg2rad(90.833)) - sin($latRad) * sin($decRad)) / (cos($latRad) * cos($decRad));
            $cosHourAngle = max(-1, min(1, $cosHourAngle));
            $hourAngle = acos($cosHourAngle);

            $dayLength = rad2deg($hourAngle) * 2 / 15;

            // Approximate solar noon in UTC: each 15° of longitude shifts solar noon by 1 hour,
            // earlier for locations east of Greenwich.
            //
            // NOTE: the previous implementation added `($lng * 4 / 60)` on top of `($lng / 15)`.
            // Those are the same quantity expressed in different units (15°/hr == 4 min/°), so
            // they cancelled out and `$noon` always evaluated to exactly 12, regardless of
            // longitude. That silently broke sunrise/sunset for every location. Fixed here.
            $noon = 12 - ($lng / 15);
            $sunrise = $noon - $dayLength / 2;
            $sunset = $noon + $dayLength / 2;

            $sunAltitude = max(0, 90 - rad2deg(abs($latRad - $decRad)));

            return [
                // sunrise/sunset/solar_noon below are UTC times, not local clock times.
                // Convert using `timezone` (or the offset your app already resolves) before
                // displaying them next to the AI's location/timezone reasoning.
                'sunrise' => $this->formatTime($sunrise),
                'sunset' => $this->formatTime($sunset),
                'solar_noon' => $this->formatTime($noon),
                'day_length' => $this->formatDuration($dayLength),
                'sun_altitude' => round($sunAltitude, 1),
                'timezone' => $this->timezone($lat, $lng),
            ];
        } catch (\Throwable $e) {
            Log::warning('SunService: ' . $e->getMessage(), ['lat' => $lat, 'lng' => $lng]);

            return [
                'sunrise' => null, 'sunset' => null, 'solar_noon' => null,
                'day_length' => null, 'sun_altitude' => null, 'timezone' => 'Unknown',
            ];
        }
    }

    private function timezone(float $lat, float $lng): string
    {
        $apiKey = config('services.timezonedb.key');

        if (empty($apiKey)) {
            Log::warning('SunService: missing timezonedb API key');

            return 'Unknown';
        }

        $cacheKey = sprintf('timezone:%s:%s', round($lat, 3), round($lng, 3));

        return Cache::remember($cacheKey, self::TIMEZONE_CACHE_TTL_SECONDS, function () use ($lat, $lng, $apiKey) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)->get('https://api.timezonedb.com/v2.1/get-time-zone', [
                    'key' => $apiKey,
                    'format' => 'json',
                    'by' => 'position',
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return $response->successful() ? ($response->json('zoneName') ?? 'Unknown') : 'Unknown';
            } catch (\Throwable $e) {
                Log::warning('SunService (timezone): ' . $e->getMessage());

                return 'Unknown';
            }
        });
    }

    private function formatTime(float $hours): string
    {
        $hours = fmod($hours, 24);
        if ($hours < 0) {
            $hours += 24;
        }
        $h = floor($hours);
        $m = round(($hours - $h) * 60);

        return sprintf('%02d:%02d', $h, $m);
    }

    private function formatDuration(float $hours): string
    {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);

        return "{$h}h {$m}m";
    }
}