<?php

namespace App\Services;

use App\Services\Gemini\ConfidenceCalculator;
use App\Services\Gemini\SecretSanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class OpenStreetMapService
{
    /** Nominatim usage policy: identify the app and respect 1 req/s. */
    private const NOMINATIM_CACHE_SECONDS = 600;

    private const NOMINATIM_LIMIT_KEY = 'nominatim_request';

    /** Wikidata (P625) gazetteer policy: cache + 5s timeout, never fail the analysis. */
    private const WIKIDATA_CACHE_SECONDS = 600;

    private const WIKIDATA_LIMIT_KEY = 'wikidata_request';

    /** Total time budget for all gazetteer HTTP calls in one resolution. */
    private const GAZETTEER_BUDGET_SECONDS = 8.0;

    /** Outcome of the most recent geocode call, for explain/telemetry. */
    private ?string $lastOutcome = null;

    private ?float $gazetteerDeadline = null;

    /** @var null|callable():float */
    private $clock = null;

    public function lastOutcomeCategory(): ?string
    {
        return $this->lastOutcome;
    }

    /** @param null|callable():float $clock */
    public function setClock(?callable $clock): void
    {
        $this->clock = $clock;
    }

    private function now(): float
    {
        if (is_callable($this->clock)) {
            return (float) call_user_func($this->clock);
        }

        return microtime(true);
    }

    private function startGazetteerBudget(): void
    {
        $this->gazetteerDeadline = $this->now() + self::GAZETTEER_BUDGET_SECONDS;
    }

    private function gazetteerBudgetExceeded(): bool
    {
        return $this->gazetteerDeadline !== null && $this->now() >= $this->gazetteerDeadline;
    }

    private function gazetteerRemainingSeconds(): float
    {
        if ($this->gazetteerDeadline === null) {
            return self::GAZETTEER_BUDGET_SECONDS;
        }

        return max(0.0, $this->gazetteerDeadline - $this->now());
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
            $this->recordOutcome('rate_limit');
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

    /** Cache-backed limiter for the Wikidata gazetteer (no sleep, cross-worker). */
    private function allowWikidata(string $operation): bool
    {
        if (RateLimiter::tooManyAttempts(self::WIKIDATA_LIMIT_KEY, 1)) {
            $this->recordOutcome('blocked', 'wikidata');
            Log::warning('Wikidata request skipped', [
                'category' => 'blocked',
                'provider' => 'wikidata',
                'operation' => $operation,
            ]);

            return false;
        }
        RateLimiter::hit(self::WIKIDATA_LIMIT_KEY, 1);

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
                $this->recordOutcome('ok');

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

    /** Last outcome is cached (no secrets) so admin health can diagnose it. */
    private function recordOutcome(string $category, string $provider = 'nominatim'): void
    {
        $this->lastOutcome = $category;
        $key = $provider === 'wikidata' ? 'wikidata:last_outcome' : 'nominatim:last_outcome';
        Cache::put($key, [
            'outcome' => $category,
            'at' => now()->toIso8601String(),
        ], 3600);
    }

    private function logNominatimFailure(string $operation, string $category, array $context, string $provider = 'nominatim'): void
    {
        if ($operation === 'wikidata' || $provider === 'wikidata') {
            $provider = 'wikidata';
            if ($category === 'rate_limit') {
                $category = 'blocked';
            }
        }
        $this->recordOutcome($category, $provider);
        Log::warning(($provider === 'wikidata' ? 'Wikidata' : 'Nominatim').' geocode failed', array_merge([
            'category' => $category,
            'provider' => $provider,
            'operation' => $operation,
        ], $context));
    }

    /**
     * Search for places with category filter
     */
    /**
     * Gazetteer lookup for a named landmark via Wikidata P625 (first source).
     * Cache-backed, 5s timeout, never throws: any failure returns null and the
     * caller falls through to the next source (Nominatim).
     */
    public function wikidataGeocode(string $landmark, ?string $city = null, ?string $country = null): ?array
    {
        $query = trim(implode(', ', array_filter([
            $landmark, $city, $country,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
        if ($query === '') {
            $this->recordOutcome('skipped_no_query');

            return null;
        }

        $cacheKey = 'wikidata_'.md5($query);
        if (Cache::has($cacheKey)) {
            $this->recordOutcome('ok', 'wikidata');

            return Cache::get($cacheKey);
        }
        if (! $this->allowWikidata('wikidata')) {
            return null;
        }

        if ($this->gazetteerBudgetExceeded()) {
            $this->recordOutcome('timeout', 'wikidata');

            return null;
        }

        try {
            $search = Http::timeout(max(0.1, min(5.0, $this->gazetteerRemainingSeconds())))
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://www.wikidata.org/w/api.php', [
                    'action' => 'wbsearchentities',
                    'search' => $query,
                    'language' => 'en',
                    'type' => 'item',
                    'limit' => 1,
                    'format' => 'json',
                ]);

            if (! $search->successful()) {
                $this->logNominatimFailure('wikidata', $search->status() === 429 ? 'rate_limit' : 'http_error', [
                    'status' => $search->status(),
                ]);

                return null;
            }

            $searchData = $search->json();
            $entityId = is_array($searchData) ? ($searchData['search'][0]['id'] ?? null) : null;
            if (! is_string($entityId) || $entityId === '') {
                $this->logNominatimFailure('wikidata', 'empty', ['status' => $search->status()]);

                return null;
            }

            $entity = Http::timeout(max(0.1, min(5.0, $this->gazetteerRemainingSeconds())))
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://www.wikidata.org/wiki/Special/EntityData/'.$entityId.'.json', [
                    'format' => 'json',
                ]);

            if (! $entity->successful()) {
                $this->logNominatimFailure('wikidata', $entity->status() === 429 ? 'rate_limit' : 'http_error', [
                    'status' => $entity->status(),
                ]);

                return null;
            }

            $entityData = $entity->json();
            $entityClaims = is_array($entityData) && isset($entityData['entities'][$entityId]['claims'])
                ? $entityData['entities'][$entityId]['claims']
                : [];

            $p625Claims = is_array($entityClaims['P625'] ?? null) ? $entityClaims['P625'] : null;
            $claim = is_array($p625Claims) ? ($p625Claims[0] ?? $p625Claims) : null;
            $value = is_array($claim) && isset($claim['mainsnak']['datavalue']['value'])
                ? $claim['mainsnak']['datavalue']['value']
                : (is_array($p625Claims) && isset($p625Claims['mainsnak']['datavalue']['value']) ? $p625Claims['mainsnak']['datavalue']['value'] : null);
            if (! is_array($value) || ! isset($value['latitude'], $value['longitude'])
                || ! is_numeric($value['latitude']) || ! is_numeric($value['longitude'])) {
                // No usable P625 coordinate: report "empty", never invent one.
                $this->logNominatimFailure('wikidata', 'empty', ['status' => $entity->status()]);
                $this->recordOutcome('empty');

                return null;
            }

            // The P31 class of the matched entity drives the landmark allowlist
            // (building / monument / palace / ...). Null when the claim is absent.
            $p31Id = $this->wikidataPropertyId($entityClaims, 'P31');

            // Require P17 (country) to match the model country (ISO compare) when provided.
            $modelCountry = is_string($country) ? trim($country) : '';
            if ($modelCountry !== '') {
                $p17 = $this->wikidataCountryCode($entityClaims);
                $modelCode = $this->normalizeCountryCode($modelCountry);
                if ($p17 === null || $modelCode === null || $p17 !== $modelCode) {
                    $this->logNominatimFailure('wikidata', 'country_mismatch', [
                        'entity' => $entityId,
                        'p17' => $p17,
                        'model' => $modelCode,
                    ]);

                    return null;
                }

                $countryCode = $p17;
            } else {
                $countryCode = $this->normalizeCountryCode($modelCountry) ?? $this->wikidataCountryCode($entityClaims);
            }

            $entityNode = is_array($entityData['entities'][$entityId] ?? null)
                ? $entityData['entities'][$entityId]
                : [];
            $label = $searchData['search'][0]['label']
                ?? $entityNode['labels']['en']['value']
                ?? $searchData['search'][0]['display']['label']['value']
                ?? $entityId;

            if (! $this->wikidataNameMatches($landmark, $entityNode, (string) $label)) {
                $this->logNominatimFailure('wikidata', 'empty', [
                    'entity' => $entityId,
                    'label' => (string) $label,
                    'reason' => 'name_mismatch',
                ]);

                return null;
            }

            $result = [
                'lat' => (float) $value['latitude'],
                'lng' => (float) $value['longitude'],
                'display_name' => (string) $label,
                'source' => 'wikidata',
                'query' => $query,
                'entity_id' => (string) $entityId,
                'country_code' => $countryCode,
                'class_id' => $p31Id,
            ];

            Cache::put($cacheKey, $result, self::WIKIDATA_CACHE_SECONDS);
            $this->recordOutcome('ok', 'wikidata');

            return $result;
        } catch (ConnectionException $e) {
            $this->logNominatimFailure('wikidata', 'timeout', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        } catch (\Throwable $e) {
            $this->logNominatimFailure('wikidata', 'bad_response', [
                'message' => SecretSanitizer::redact($e->getMessage()),
            ]);
        }

        return null;
    }

    /**
     * Resolve the Wikidata entity's P17 (country) to an ISO 3166-1 alpha-2 code.
     * Returns null if P17 is missing/unresolvable or on any failure.
     */
    private function wikidataCountryCode(array $entityClaims): ?string
    {
        $p17Claims = $entityClaims['P17'] ?? null;
        $p17Claim = is_array($p17Claims) ? ($p17Claims[0] ?? $p17Claims) : null;
        $countryId = is_array($p17Claim) ? ($p17Claim['mainsnak']['datavalue']['value']['id'] ?? null) : null;
        if (! is_string($countryId) || $countryId === '') {
            return null;
        }

        $cacheKey = 'wikidata_p297_'.md5($countryId);
        if (Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);

            return is_string($cached) && $cached !== '' ? strtolower($cached) : null;
        }
        // Follow-up P17 lookup: the main wikidata call was already rate-limited.
        if ($this->gazetteerBudgetExceeded()) {
            $this->recordOutcome('timeout', 'wikidata');

            return null;
        }

        try {
            $resp = Http::timeout(max(0.1, min(5.0, $this->gazetteerRemainingSeconds())))
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://www.wikidata.org/wiki/Special/EntityData/'.$countryId.'.json');

            if (! $resp->successful()) {
                return null;
            }
            $data = $resp->json();
            $p297Claims = is_array($data) ? ($data['entities'][$countryId]['claims']['P297'] ?? null) : null;
            $p297Claim = is_array($p297Claims) ? ($p297Claims[0] ?? $p297Claims) : null;
            $code = is_array($p297Claim) ? ($p297Claim['mainsnak']['datavalue']['value'] ?? null) : null;
            $code = is_string($code) ? strtolower(trim($code)) : null;
            if ($code) {
                Cache::put($cacheKey, $code, self::WIKIDATA_CACHE_SECONDS);
            }

            return $code ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeCountryCode(string $country): ?string
    {
        try {
            $calc = app(ConfidenceCalculator::class);

            return $calc->normalizeCountryCode($country);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Resolve a Wikidata property (P625 / P17 / P31) mainsnak value to its
     * entity id. Returns null when the claim is absent or malformed. Handles
     * both statement shapes the Wikidata API can return (list-wrapped or bare).
     */
    private function wikidataPropertyId(array $entityClaims, string $property): ?string
    {
        $claims = $entityClaims[$property] ?? null;
        if (! is_array($claims)) {
            return null;
        }
        $claim = $claims[0] ?? $claims;
        if (! is_array($claim) || empty($claim['mainsnak']['datavalue']['value'])) {
            return null;
        }
        $value = $claim['mainsnak']['datavalue']['value'];
        if (isset($value['id'])) {
            return (string) $value['id'];
        }

        return null;
    }

    /**
     * Phase-2 landmark allowlist: a gazetteer-backed answer whose Wikidata
     * P31 class is a building / monument / palace / castle / statue / memorial
     * is a landmark-precise candidate. Settlements, regions and natural
     * features are not in this allowlist and stay "approximate".
     */
    public function isLandmarkClass(?string $classId): bool
    {
        $id = $classId !== null ? trim((string) $classId) : '';

        if ($id === '') {
            return false;
        }

        return in_array($id, [
            'Q16560', // palace
            'Q23413', // castle
            'Q41176', // building
            'Q179700', // statue
            'Q4989906', // monument
            'Q5003624', // memorial
            'Q53536964', // royal palace
        ], true);
    }

    /**
     * Accept a Wikidata hit only when its label or alias matches the model
     * landmark name after normalization, or similarity >= 0.85.
     */
    private function wikidataNameMatches(string $landmark, array $entityNode, string $fallbackLabel): bool
    {
        $needle = $this->normalizeLandmarkName($landmark);
        if ($needle === '') {
            return true;
        }

        $candidates = [$fallbackLabel];

        $label = $entityNode['labels']['en']['value'] ?? null;
        if (is_string($label) && $label !== '') {
            $candidates[] = $label;
        }

        $aliases = $entityNode['aliases']['en'] ?? [];
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                $value = is_array($alias) ? ($alias['value'] ?? null) : null;
                if (is_string($value) && $value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $normalized = $this->normalizeLandmarkName((string) $candidate);
            if ($normalized === '') {
                continue;
            }
            if ($normalized === $needle) {
                return true;
            }
            if (str_contains($normalized, $needle) || str_contains($needle, $normalized)) {
                return true;
            }
            similar_text($normalized, $needle, $percent);
            if (($percent / 100) >= 0.85) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLandmarkName(string $name): string
    {
        $value = Str::ascii($name);
        $value = strtolower($value);
        $value = preg_replace('/\bthe\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Split a compound landmark name ("A and B", "A / B", "A & B") into its
     * parts. A plain name returns a single-element array.
     */
    public function splitCompoundName(string $name): array
    {
        $clean = trim($name);
        if ($clean === '') {
            return [];
        }

        $parts = preg_split('/\s+(?:and|\/|&)\s+/i', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: [$clean];

        $result = array_map('trim', $parts);
        $result = array_values(array_filter($result, static fn ($part) => $part !== ''));

        return $result === [] ? [$clean] : $result;
    }

    /**
     * Forward geocode a place name for an informational distance signal.
     * Gazetteer chain: Wikidata P625 first, Nominatim second. Returns lat/lng
     * plus the display name, or null on any failure.
     */
    public function forwardGeocode(string $landmark, ?string $city = null, ?string $country = null): ?array
    {
        $query = trim(implode(', ', array_filter([
            $landmark, $city, $country,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
        if ($query === '') {
            $this->recordOutcome('skipped_no_query');

            return null;
        }

        $cacheKey = 'forward_'.md5($query);
        if (Cache::has($cacheKey)) {
            $this->recordOutcome('ok');

            return Cache::get($cacheKey);
        }
        $this->startGazetteerBudget();
        // Gazetteer chain for a named landmark: Wikidata P625 first, Nominatim second.
        $wikidata = $this->wikidataGeocode($landmark, $city, $country);
        if (is_array($wikidata) && isset($wikidata['lat'], $wikidata['lng']) && is_numeric($wikidata['lat']) && is_numeric($wikidata['lng'])) {
            $wikidata['source'] = 'wikidata';
            Cache::put($cacheKey, $wikidata, self::NOMINATIM_CACHE_SECONDS);
            $this->recordOutcome('ok', 'wikidata');

            return $wikidata;
        }

        if ($this->gazetteerBudgetExceeded()) {
            $this->recordOutcome('timeout');

            return null;
        }

        if (! $this->allowNominatim('forward')) {
            return null;
        }

        try {
            $response = Http::timeout(max(0.1, min(5.0, $this->gazetteerRemainingSeconds())))
                ->withHeaders(['User-Agent' => $this->nominatimUserAgent()])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                $this->logNominatimFailure('forward', $response->status() === 429 ? 'rate_limit' : 'http_error', [
                    'status' => $response->status(),
                ]);

                return null;
            }
            $data = $response->json();
            if (! is_array($data) || ! isset($data[0]['lat'], $data[0]['lon'])
                || ! is_numeric($data[0]['lat']) || ! is_numeric($data[0]['lon'])) {
                // No usable place: report "empty", never invent coordinates.
                $this->logNominatimFailure('forward', 'empty', ['status' => $response->status()]);

                return null;
            }

            $address = is_array($data[0]['address'] ?? null) ? $data[0]['address'] : [];
            $result = [
                'lat' => (float) $data[0]['lat'],
                'lng' => (float) $data[0]['lon'],
                'display_name' => (string) ($data[0]['display_name'] ?? ''),
                'country_code' => $address['country_code'] ?? ($data[0]['country_code'] ?? null),
                'source' => 'nominatim',
                'query' => $query,
            ];
            Cache::put($cacheKey, $result, self::NOMINATIM_CACHE_SECONDS);
            $this->recordOutcome('ok');

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
