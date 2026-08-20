<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    /**
     * Weather is time-sensitive — keep the cache short.
     */
    private const CACHE_TTL_SECONDS = 60 * 15;

    private const TIMEOUT_SECONDS = 5;

    public function getWeather(float $lat, float $lng): array
    {
        $apiKey = config('services.openweather.key');

        if (empty($apiKey)) {
            Log::warning('WeatherService: missing OpenWeather API key');

            return $this->unavailable();
        }

        $cacheKey = sprintf('weather:%s:%s', round($lat, 3), round($lng, 3));

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($lat, $lng, $apiKey) {
            return $this->fetchWeather($lat, $lng, $apiKey);
        });
    }

    private function fetchWeather(float $lat, float $lng, string $apiKey): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(2, 300)
                ->get('https://api.openweathermap.org/data/2.5/weather', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'appid' => $apiKey,
                    'units' => 'metric',
                ]);

            if (! $response->successful()) {
                Log::warning('WeatherService: non-2xx response', [
                    'status' => $response->status(),
                    'lat' => $lat,
                    'lng' => $lng,
                ]);

                return $this->unavailable();
            }

            $data = $response->json();

            // OpenWeather's `timezone` field is the location's UTC offset in seconds.
            // Using date() on the raw sunrise/sunset unix timestamps formats them in the
            // *server's* timezone, not the location's — this applies the correction.
            $tzOffset = $data['timezone'] ?? 0;

            return [
                'temp' => round($data['main']['temp']),
                'temp_min' => round($data['main']['temp_min']),
                'temp_max' => round($data['main']['temp_max']),
                'feels_like' => round($data['main']['feels_like']),
                'humidity' => $data['main']['humidity'],
                'pressure' => $data['main']['pressure'],
                'description' => $data['weather'][0]['description'] ?? null,
                'icon' => $data['weather'][0]['icon'] ?? null,
                'wind_speed' => $data['wind']['speed'] ?? null,
                'clouds' => $data['clouds']['all'] ?? null,
                'sunrise' => isset($data['sys']['sunrise'])
                    ? gmdate('H:i', $data['sys']['sunrise'] + $tzOffset)
                    : null,
                'sunset' => isset($data['sys']['sunset'])
                    ? gmdate('H:i', $data['sys']['sunset'] + $tzOffset)
                    : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('WeatherService: ' . $e->getMessage(), ['lat' => $lat, 'lng' => $lng]);

            return $this->unavailable();
        }
    }

    private function unavailable(): array
    {
        return [
            'temp' => null,
            'temp_min' => null,
            'temp_max' => null,
            'feels_like' => null,
            'humidity' => null,
            'pressure' => null,
            'description' => 'Weather data unavailable',
            'icon' => null,
            'wind_speed' => null,
            'clouds' => null,
            'sunrise' => null,
            'sunset' => null,
        ];
    }
}