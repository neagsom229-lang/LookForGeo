<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Approximate geolocation from a request's IP address — a last-resort,
 * always-available prior when neither the photo's own EXIF GPS nor the
 * browser's device GPS hint is available (no permission was granted, or
 * geolocation timed out).
 *
 * IP geolocation is city-level at best — often +/-50km, and can be badly
 * wrong behind VPNs, mobile carrier NAT, or corporate proxies. This is
 * deliberately tagged with a large accuracy_m so both the AI prompt
 * (LandmarkRecognitionService) and the geocoder's bias radius
 * (GeocodingService) treat it as a much weaker prior than device GPS,
 * never as good as it.
 *
 * Priority order enforced by LandmarkController: EXIF GPS > browser GPS
 * hint > this IP fallback > nothing. This class is only ever consulted
 * when the two better signals are both unavailable.
 */
class IpGeolocationService
{
    private const CACHE_TTL_HOURS = 12;

    public function locate(string $ip): ?array
    {
        if ($this->isPrivateOrLoopback($ip)) {
            // Meaningless to geolocate a dev/loopback/LAN address — and we
            // shouldn't send it to a third party anyway.
            return null;
        }

        $cacheKey = 'ip_geo_' . md5($ip);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            // ipapi.co: free tier, HTTPS, no API key, ~1,000 requests/day.
            // For production volume beyond that, swap this call for a
            // self-hosted MaxMind GeoLite2 database lookup instead — same
            // return shape, no per-request network call or rate limit.
            $response = Http::timeout(4)->get("https://ipapi.co/{$ip}/json/");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if (empty($data['latitude']) || empty($data['longitude']) || ! empty($data['error'])) {
                return null;
            }

            $result = [
                'lat' => (float) $data['latitude'],
                'lng' => (float) $data['longitude'],
                'city' => $data['city'] ?? null,
                'country' => $data['country_name'] ?? null,
                // Deliberately coarse — communicates "city-level at best" to
                // every downstream consumer of this hint.
                'accuracy_m' => 50000,
            ];

            Cache::put($cacheKey, $result, now()->addHours(self::CACHE_TTL_HOURS));

            return $result;
        } catch (\Throwable $e) {
            Log::warning('IpGeolocationService: ' . $e->getMessage());

            return null;
        }
    }

    private function isPrivateOrLoopback(string $ip): bool
    {
        // FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE reject
        // 10.x/172.16.x/192.168.x, loopback, and other reserved ranges in
        // one call — covers localhost/dev/LAN without a manual prefix list.
        return ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
}