<?php

namespace App\Services\Gemini;

use App\Models\Landmark;
use App\Services\OpenStreetMapService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class GeminiResultEnhancer
{
    /**
     * A named-landmark coordinate from the model only is an estimate; the
     * gazetteer-backed answer wins when the two disagree by more than this.
     */
    private const GAZETTEER_PREFER_KM = 5.0;

    public function __construct(
        private ConfidenceCalculator $confidence,
        private OpenStreetMapService $osm
    ) {}

    /**
     * RUN THE ENRICHMENT CHAIN + FIELD VALIDATION.
     * Confidence is computed from checkable signals by ConfidenceCalculator,
     * never from the model's self-reported number.
     */
    public function enhance($data, array $metadata = [])
    {
        $data = $this->databaseCrossReference($data);
        $data = $this->multiSourceVerification($data);
        $data = $this->applyGazetteerPreference($data);
        $data = $this->resolveMissingCoordinates($data);
        $data = $this->confidence->calculate($data, $metadata);
        $data = $this->addRichMetadata($data);

        $data['location_precision'] = $this->resolveLocationPrecision($data);
        $data['uncertainty_radius_m'] = $this->resolveUncertaintyRadius($data);

        return $this->validateAllFields($data);
    }

    /**
     * Re-apply the calibrated score after a second model's answer becomes
     * available. No model or geocoder call: it only adds the agreement
     * signal and re-runs the cap/floor rules.
     */
    public function applySecondaryModelAnswer(array $data, array $secondary): array
    {
        return $this->confidence->withSecondaryModelAnswer($data, $secondary);
    }

    private function isValidCoordinate($lat, $lng)
    {
        return is_numeric($lat) && is_numeric($lng)
            && is_finite((float) $lat) && is_finite((float) $lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180
            && ! ((float) $lat === 0.0 && (float) $lng === 0.0);
    }

    /**
     * Last-resort forward geocode when the model found a place but returned no
     * coordinates. Nominatim is rate-limited (1 req/s), cached and uses the
     * configured contact User-Agent. Any failure keeps coordinates null and
     * never fails the analysis; the outcome category is carried in the result.
     */
    private function resolveMissingCoordinates(array $data): array
    {
        if ($this->isValidCoordinate($data['latitude'] ?? null, $data['longitude'] ?? null)) {
            return $data;
        }
        if (! config('services.gemini.forward_geocode_missing', true)) {
            return $data;
        }

        $landmark = trim((string) ($data['landmark_name'] ?? ''));
        $city = trim((string) ($data['city'] ?? ''));
        $country = trim((string) ($data['country'] ?? ''));
        if ($landmark === '' && $city === '' && $country === '') {
            return $data;
        }

        try {
            $place = $this->osm->forwardGeocode(
                $landmark,
                $city !== '' ? $city : null,
                $country !== '' ? $country : null
            );
        } catch (Throwable $e) {
            Log::warning('Forward geocode for missing coordinates failed: '.get_class($e));
            $place = null;
        }

        $outcome = $this->osm->lastOutcomeCategory();
        $data['forward_geocode_outcome'] = $outcome;

        if ($this->isValidCoordinate($place['lat'] ?? null, $place['lng'] ?? null)) {
            // A geocode of the model's own name can match a different country
            // (homonyms, wrong continent). Accept it only when the ISO country
            // code agrees with the model's stated country.
            $placeCode = $this->confidence->normalizeCountryCode(
                isset($place['country_code']) ? (string) $place['country_code'] : null
            );
            if ($placeCode === null) {
                $parts = array_values(array_filter(array_map('trim', explode(
                    ',', (string) ($place['display_name'] ?? '')
                ))));
                $placeCode = $this->confidence->normalizeCountryCode(
                    $parts === [] ? null : (string) end($parts)
                );
            }
            $modelCode = $this->confidence->normalizeCountryCode($country !== '' ? $country : null);
            if ($modelCode !== null && $placeCode !== null && $modelCode !== $placeCode) {
                $data['forward_geocode_outcome'] = 'country_mismatch';
                Log::warning('Forward geocode country mismatch', [
                    'model_country' => $modelCode,
                    'geocoded_country' => $placeCode,
                ]);
                $data['latitude'] = null;
                $data['longitude'] = null;

                return $data;
            }

            $data['latitude'] = (float) $place['lat'];
            $data['longitude'] = (float) $place['lng'];
            $data['coordinate_source'] = 'forward_geocode';

            return $data;
        }

        // Honest absence: never substitute a famous landmark or (0, 0).
        $data['latitude'] = null;
        $data['longitude'] = null;

        return $data;
    }

    private function extractCoordinatesFromText($text)
    {
        if (preg_match('/(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/', $text, $m)) {
            $lat = floatval($m[1]);
            $lng = floatval($m[2]);
            if ($this->isValidCoordinate($lat, $lng)) {
                return ['lat' => $lat, 'lng' => $lng];
            }
        }

        return null;
    }

    /**
     * Whether the Landmark model is actually usable right now.
     * Both databaseCrossReference and multiSourceVerification rely on this
     * so a missing table/model can't cause an uncaught exception.
     */
    private function landmarkModelAvailable(): bool
    {
        try {
            return class_exists(Landmark::class)
                && Schema::hasTable((new Landmark)->getTable());
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * DATABASE CROSS-REFERENCE
     */
    private function databaseCrossReference($data)
    {
        $name = $data['landmark_name'] ?? '';
        $city = $data['city'] ?? '';
        $country = $data['country'] ?? '';

        if (empty($name) && empty($city) && empty($country)) {
            return $data;
        }

        if (! $this->landmarkModelAvailable()) {
            return $data;
        }

        try {
            $query = Landmark::query();

            if (! empty($name)) {
                $query->where('name', 'LIKE', "%{$name}%");
            }
            if (! empty($city)) {
                $query->orWhere('city', 'LIKE', "%{$city}%");
            }
            if (! empty($country)) {
                $query->orWhere('country', 'LIKE', "%{$country}%");
            }

            $landmarks = $query->limit(3)->get();

            if ($landmarks->isNotEmpty()) {
                $landmark = $landmarks->first();
                $data['landmark_name'] = $landmark->name;
                $data['city'] = $landmark->city ?? $data['city'];
                $data['country'] = $landmark->country;
                $data['region'] = $landmark->region ?? ($data['region'] ?? null);
                $data['latitude'] = $landmark->latitude;
                $data['longitude'] = $landmark->longitude;
                $data['matched_landmark'] = $landmark->name;
                $data['reasoning'] = ($data['reasoning'] ?? '')." Confirmed by database match: {$landmark->name}.";
                $data['confidence'] = max($data['confidence'] ?? 50, 85);
            }
        } catch (\Exception $e) {
            Log::warning('Landmark DB search failed: '.$e->getMessage());
        }

        return $data;
    }

    /**
     * MULTI-SOURCE COORDINATE VERIFICATION (Voting)
     */
    private function multiSourceVerification($data)
    {
        $coords = [];

        if (isset($data['latitude'], $data['longitude']) && $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            $coords[] = ['lat' => $data['latitude'], 'lng' => $data['longitude'], 'weight' => 3];
        }

        if (isset($data['reasoning'])) {
            $extracted = $this->extractCoordinatesFromText($data['reasoning']);
            if ($extracted) {
                $coords[] = ['lat' => $extracted['lat'], 'lng' => $extracted['lng'], 'weight' => 2];
            }
        }

        // Guard: only touch the Landmark model if it's actually available.
        // The original code called Landmark::where(...) unconditionally here,
        // which would throw a fatal error if the table/model wasn't set up.
        if (isset($data['matched_landmark']) && $this->landmarkModelAvailable()) {
            try {
                $landmark = Landmark::where('name', $data['matched_landmark'])->first();
                if ($landmark && $landmark->latitude && $landmark->longitude) {
                    $coords[] = ['lat' => $landmark->latitude, 'lng' => $landmark->longitude, 'weight' => 4];
                }
            } catch (\Exception $e) {
                Log::warning('Landmark coordinate lookup failed: '.$e->getMessage());
            }
        }

        if (empty($coords)) {
            return $data;
        }

        $totalWeight = array_sum(array_column($coords, 'weight'));
        $avgLat = array_sum(array_map(fn ($c) => $c['lat'] * $c['weight'], $coords)) / $totalWeight;
        $avgLng = array_sum(array_map(fn ($c) => $c['lng'] * $c['weight'], $coords)) / $totalWeight;

        $data['latitude'] = round($avgLat, 6);
        $data['longitude'] = round($avgLng, 6);
        $data['coordinate_sources'] = count($coords);

        return $data;
    }

    /**
     * Named-landmark gazetteer preference (Wikimedia-style coordinate
     * authority). Runs after the model answer and its cross-source average;
     * when a landmark was identified and the model coordinates disagree with
     * the gazetteer by more than GAZETTEER_PREFER_KM, the gazetteer answer
     * wins. Any failure is swallowed: a geocode problem must never fail the
     * analysis, the model answer stays in place.
     */
    private function applyGazetteerPreference(array $data): array
    {
        if (! $this->isIdentified($data)) {
            return $data;
        }
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng) || ! $this->isValidCoordinate($lat, $lng)) {
            // Missing coordinates are resolved later via forward geocode.
            return $data;
        }
        if (($data['coordinate_source'] ?? null) === 'gazetteer' || ($data['coordinate_source'] ?? null) === 'forward_geocode') {
            // Already gazetteer-backed (or resolved later): no model-vs-gazetteer
            // comparison is meaningful in that state.
            return $data;
        }
        if (! config('services.gemini.confidence_forward_geocode', true)) {
            return $data;
        }

        try {
            $place = $this->osm->forwardGeocode(
                (string) ($data['landmark_name'] ?? ''),
                isset($data['city']) && is_string($data['city']) ? $data['city'] : null,
                isset($data['country']) && is_string($data['country']) ? $data['country'] : null
            );
        } catch (\Throwable $e) {
            return $data;
        }

        if (! is_array($place) || ! isset($place['lat'], $place['lng']) || ! is_numeric($place['lat']) || ! is_numeric($place['lng'])) {
            // No usable gazetteer hit: keep the honest model answer.
            return $data;
        }

        $distanceKm = $this->osm->calculateDistance(
            (float) $lat, (float) $lng, (float) $place['lat'], (float) $place['lng']
        ) / 1000;

        if ($distanceKm > self::GAZETTEER_PREFER_KM) {
            $data['coordinate_disagreement_km'] = round($distanceKm, 2);
            $data['latitude'] = (float) $place['lat'];
            $data['longitude'] = (float) $place['lng'];
            $data['coordinate_source'] = 'gazetteer';
            $data['forward_geocode_outcome'] = 'disagree';

            return $data;
        }

        return $data;
    }

    private function isIdentified(array $data): bool
    {
        $name = strtolower(trim((string) ($data['landmark_name'] ?? '')));

        return $name !== ''
            && ! in_array($name, ['unknown', 'unknown location', 'n/a', 'none'], true);
    }

    /**
     * location_precision: landmark (gazetteer-backed), approximate (model
     * estimate) or region (no identifiable precise place).
     */
    private function resolveLocationPrecision(array $data): string
    {
        if (! $this->isIdentified($data)) {
            return 'region';
        }
        if (($data['coordinate_source'] ?? null) === 'gazetteer') {
            return 'landmark';
        }
        if (isset($data['latitude'], $data['longitude']) && $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            return 'approximate';
        }

        return 'region';
    }

    /**
     * Uncertainty radius in meters for the returned precision. A gazetteer
     * answer is a known place (near zero); a model-only estimate carries the
     * model uncertainty; an unidentified answer is region-level.
     */
    private function resolveUncertaintyRadius(array $data): float
    {
        if (! $this->isIdentified($data)) {
            return 50_000.0;
        }
        if (($data['coordinate_source'] ?? null) === 'gazetteer') {
            return 0.0;
        }
        if (isset($data['latitude'], $data['longitude']) && $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            return 5_000.0;
        }

        return 50_000.0;
    }

    /**
     * ADD RICH METADATA
     */
    private function addRichMetadata($data)
    {
        if (empty($data['continent'])) {
            $data['continent'] = $this->getContinent($data['country'] ?? '');
        }
        if (isset($data['latitude'], $data['longitude']) && ! is_null($data['latitude']) && ! is_null($data['longitude'])) {
            $data['timezone'] = $this->getTimezoneEstimate($data['latitude'], $data['longitude']);
        } else {
            $data['timezone'] = 'Unknown';
        }
        if (empty($data['visual_clues'])) {
            $data['visual_clues'] = ['General scenery'];
        }
        if (empty($data['tags'])) {
            $data['tags'] = ['Geolocation', 'OSINT', 'AI Analysis'];
        }

        return $data;
    }

    /**
     * VALIDATE ALL FIELDS (ensure no nulls)
     */
    private function validateAllFields($data)
    {
        $defaults = [
            'landmark_name' => 'Unknown Location',
            'city' => 'Unknown',
            'country' => 'Unknown',
            'region' => 'Unknown',
            'latitude' => null,
            'longitude' => null,
            'confidence' => 0,
            'reasoning' => 'No reasoning provided.',
            'visual_clues' => [],
            'tags' => ['Geolocation', 'OSINT', 'AI Analysis'],
            'description' => 'No description.',
            'type' => 'unknown',
            'continent' => 'Unknown',
            'timezone' => 'Unknown',
            'confidence_level' => 'Very Low',
            'low_confidence' => true,
            'model_reported_confidence' => 0,
            'confidence_basis' => 'calibrated-signals',
            'confidence_signals' => [],
            'alternatives' => [],
            'coordinate_sources' => 0,
            'negative_evidence' => 'Not specified.',
            'vantage_point' => 'ground',
            'nearby_landmarks' => [],
            'wikipedia_url' => null,
            'nearest_airport' => null,
            'candidates' => [],
            'candidate_gap' => null,
            'coordinate_source' => 'model',
            'coordinate_disagreement_km' => null,
            'location_precision' => 'region',
            'uncertainty_radius_m' => 0,
        ];

        foreach ($defaults as $key => $defaultValue) {
            // null/empty-string defaults (wikipedia_url, nearest_airport,
            // candidate_gap) are legitimate "unknown" values, not gaps to
            // fill — only backfill when the key is missing entirely.
            if (in_array($key, ['wikipedia_url', 'nearest_airport', 'candidate_gap'], true)) {
                if (! array_key_exists($key, $data)) {
                    $data[$key] = $defaultValue;
                }

                continue;
            }
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $data[$key] = $defaultValue;
            }
        }

        return $data;
    }

    /**
     * Rough longitude-based timezone estimate. NOTE: this is an
     * approximation only — real-world timezone boundaries follow
     * political borders, not clean 15° bands, so this can be off
     * by an hour or more, especially near a boundary or for
     * countries with a single timezone spanning a wide longitude
     * range (e.g. China). Label it as an estimate wherever it's
     * displayed to the user.
     */
    private function getTimezoneEstimate($lat, $lng)
    {
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return 'Unknown';
        }
        $offset = (int) round($lng / 15);
        $offset = max(-12, min(14, $offset));

        return $offset >= 0 ? "UTC+{$offset} (est.)" : "UTC{$offset} (est.)";
    }

    private function getContinent($country)
    {
        $map = [
            'cambodia' => 'Asia', 'thailand' => 'Asia', 'singapore' => 'Asia', 'china' => 'Asia',
            'japan' => 'Asia', 'india' => 'Asia', 'uae' => 'Asia', 'united states' => 'North America',
            'canada' => 'North America', 'mexico' => 'North America', 'united kingdom' => 'Europe',
            'france' => 'Europe', 'italy' => 'Europe', 'germany' => 'Europe', 'spain' => 'Europe',
            'russia' => 'Europe', 'australia' => 'Oceania', 'new zealand' => 'Oceania',
            'egypt' => 'Africa', 'south africa' => 'Africa', 'nigeria' => 'Africa',
            'brazil' => 'South America', 'argentina' => 'South America',
        ];
        $countryLower = strtolower($country);
        foreach ($map as $name => $cont) {
            if (strpos($countryLower, $name) !== false) {
                return $cont;
            }
        }

        return 'Unknown';
    }
}
