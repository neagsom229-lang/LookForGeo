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
     * A named-landmark coordinate from the model only is an estimate; when it
     * disagrees with the gazetteer-backed answer by more than this, the
     * disagreement is surfaced (never silently overriding the model answer).
     */
    private const GAZETTEER_PREFER_KM = 5.0;

    /**
     * Phase-2 landmark pin precision: a gazetteer pin (Wikidata P625) closer
     * than this to the model estimate is landmark-precise; beyond it the
     * gazetteer point wins and the disagreement is recorded.
     */
    private const LANDMARK_PIN_TOLERANCE_M = 300.0;

    /**
     * Gazetteer adoption cap: a wrong same-country hit hundreds of km away must
     * never override the model pin. Beyond this distance we keep the model pin,
     * record the disagreement, widen the radius and mark the answer approximate.
     */
    private const GAZETTEER_ADOPT_MAX_KM = 25.0;

    /** Minimum uncertainty radius (m) for a gazetteer-backed landmark pin. */
    private const MIN_GAZETTEER_RADIUS_M = 100.0;

    /** Compound landmark parts more than this apart are ambiguous. */
    private const COMPOUND_PARTS_KM = 1.0;

    /** Model-estimate / settlement / region answers get at least this radius. */
    private const AREA_RADIUS_M = 2000.0;

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
        $radius = $this->resolveUncertaintyRadius($data);
        $disagreementKm = (float) ($data['coordinate_disagreement_km'] ?? 0);
        if ($disagreementKm > 0) {
            // Never present a disagreed coordinate as more certain than the
            // evidence: widen the radius to at least the disagreement distance.
            $radius = max($radius, $disagreementKm * 1000);
        }
        if (! empty($data['compound_ambiguous'])) {
            // Compound landmark parts resolved apart: the answer is approximate.
            $data['location_precision'] = 'approximate';
            $radius = max($radius, self::AREA_RADIUS_M);
        }
        $data['uncertainty_radius_m'] = round($radius, 1);

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
     * the gazetteer by more than GAZETTEER_PREFER_KM, the disagreement is
     * recorded and both answers kept. Any failure is swallowed: a geocode
     * problem must never fail the analysis, the model answer stays in place.
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
        } catch (Throwable $e) {
            return $data;
        }

        if (! is_array($place) || ! isset($place['lat'], $place['lng']) || ! is_numeric($place['lat']) || ! is_numeric($place['lng'])) {
            // No usable gazetteer hit: keep the honest model answer.
            $data['forward_geocode_outcome'] = $this->osm->lastOutcomeCategory();

            return $data;
        }

        $distanceKm = $this->osm->calculateDistance(
            (float) $lat, (float) $lng, (float) $place['lat'], (float) $place['lng']
        ) / 1000;

        $compoundAmbiguous = $this->resolveCompoundPreference($data);

        $isLandmark = $this->isLandmarkGazetteerMatch($data, $place);

        if ($compoundAmbiguous) {
            // Compound landmark with parts clearly apart: approximate area.
            $data['location_precision'] = 'approximate';
        }

        if ($isLandmark && $distanceKm > self::LANDMARK_PIN_TOLERANCE_M / 1000) {
            if ($distanceKm > self::GAZETTEER_ADOPT_MAX_KM) {
                // Far same-country hit: too distant to be the same landmark.
                // Keep the model pin, record disagreement, widen radius, mark approximate.
                $data['coordinate_disagreement_km'] = round($distanceKm, 2);
                $data['location_precision'] = 'approximate';
                $data['uncertainty_radius_m'] = max(
                    (float) ($data['uncertainty_radius_m'] ?? 0),
                    self::AREA_RADIUS_M,
                    $distanceKm * 1000
                );
                $data['gazetteer_coordinates'] = [
                    'lat' => (float) $place['lat'],
                    'lng' => (float) $place['lng'],
                    'display_name' => (string) ($place['display_name'] ?? ''),
                    'country_code' => $place['country_code'] ?? null,
                    'source' => (string) ($place['source'] ?? 'gazetteer'),
                    'class_id' => $place['class_id'] ?? null,
                ];
                $data['forward_geocode_outcome'] = 'disagreed_far';

                return $data;
            }

            // Phase 2 landmark pin precision: the model pin is more than 300 m
            // from a same-country landmark-class gazetteer answer, so the
            // gazetteer point wins. The model estimate is kept (auditable) and
            // the disagreement is surfaced; the radius never drops below the
            // pin tolerance.
            $previousSource = (string) ($data['coordinate_source'] ?? 'model');
            $data['latitude'] = (float) $place['lat'];
            $data['longitude'] = (float) $place['lng'];
            $data['coordinate_source'] = 'gazetteer';
            $data['coordinate_disagreement_km'] = round($distanceKm, 2);
            $data['model_coordinates'] = [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
                'source' => $previousSource,
            ];
            $data['gazetteer_coordinates'] = [
                'lat' => (float) $place['lat'],
                'lng' => (float) $place['lng'],
                'display_name' => (string) ($place['display_name'] ?? ''),
                'country_code' => $place['country_code'] ?? null,
                'source' => (string) ($place['source'] ?? 'gazetteer'),
                'class_id' => $place['class_id'] ?? null,
            ];
            $data['gazetteer_landmark_class'] = true;
            $data['forward_geocode_outcome'] = 'adopted';

            return $data;
        }

        if ($isLandmark) {
            // Within the 300 m pin tolerance: the gazetteer confirms the model
            // pin, so the answer is landmark-precise (radius at the pin floor).
            $data['coordinate_source'] = 'gazetteer';
            $data['gazetteer_landmark_class'] = true;

            return $data;
        }

        if ($distanceKm > self::GAZETTEER_PREFER_KM) {
            // Do NOT silently override the model coordinates: keep the model
            // answer and record the gazetteer side as a disagreement. The
            // consumer (precision / radius / confidence) reacts honestly.
            $data['coordinate_disagreement_km'] = round($distanceKm, 2);
            $data['gazetteer_coordinates'] = [
                'lat' => (float) $place['lat'],
                'lng' => (float) $place['lng'],
                'display_name' => (string) ($place['display_name'] ?? ''),
                'country_code' => $place['country_code'] ?? null,
                'source' => (string) ($place['source'] ?? 'gazetteer'),
            ];
            $data['forward_geocode_outcome'] = 'disagree';

            return $data;
        }

        $data['gazetteer_coordinates'] = [
            'lat' => (float) $place['lat'],
            'lng' => (float) $place['lng'],
            'display_name' => (string) ($place['display_name'] ?? ''),
            'country_code' => $place['country_code'] ?? null,
            'source' => (string) ($place['source'] ?? 'gazetteer'),
        ];
        $data['forward_geocode_outcome'] = 'agrees';

        return $data;
    }

    /**
     * Phase-2 compound-name handling: resolve the first part of a compound
     * landmark ("A and B", "A / B") and flag the answer approximate when the
     * parts are more than COMPOUND_PARTS_KM apart. The first part's coordinates
     * win when they resolve.
     *
     * @return bool True when the compound parts are ambiguous (answer -> approximate).
     */
    private function resolveCompoundPreference(array &$data): bool
    {
        $name = (string) ($data['landmark_name'] ?? '');
        $parts = $this->osm->splitCompoundName($name);
        if (count($parts) < 2) {
            return false;
        }
        $city = isset($data['city']) && is_string($data['city']) && trim($data['city']) !== ''
            ? $data['city']
            : null;
        $country = isset($data['country']) && is_string($data['country']) && trim($data['country']) !== ''
            ? $data['country']
            : null;

        $first = $this->osm->forwardGeocode($parts[0], $city, $country);
        $second = $this->osm->forwardGeocode($parts[1], $city, $country);

        $firstValid = is_array($first) && isset($first['lat'], $first['lng']) && is_numeric($first['lat']) && is_numeric($first['lng']);
        if (! $firstValid) {
            // Nothing to resolve: keep the model answer, no ambiguity claim.
            return false;
        }

        $secondValid = is_array($second) && isset($second['lat'], $second['lng']) && is_numeric($second['lat']) && is_numeric($second['lng']);

        $secondLat = is_array($second) && isset($second['lat'], $second['lng']) && is_numeric($second['lat']) && is_numeric($second['lng'])
            ? (float) $second['lat']
            : (float) $first['lat'];
        $secondLng = is_array($second) && isset($second['lat'], $second['lng']) && is_numeric($second['lat']) && is_numeric($second['lng'])
            ? (float) $second['lng']
            : (float) $first['lng'];

        $distanceKm = $this->osm->calculateDistance(
            (float) $first['lat'], (float) $first['lng'], $secondLat, $secondLng
        ) / 1000;

        // Resolve the first part: it is the better-supported reading of the name.
        $data['latitude'] = (float) $first['lat'];
        $data['longitude'] = (float) $first['lng'];
        $data['coordinate_source'] = 'gazetteer';

        if ($secondValid && $distanceKm > self::COMPOUND_PARTS_KM) {
            // Compound parts are more than 1 km apart: ambiguous -> approximate.
            $data['compound_ambiguous'] = true;
        }

        return (bool) ($data['compound_ambiguous'] ?? false);
    }

    /**
     * A gazetteer hit is a landmark-precise candidate only when the matched
     * Wikidata entity is a building / monument / palace (allowlist) and the
     * resolved country agrees with the model's stated country.
     */
    private function isLandmarkGazetteerMatch(array $data, array $place): bool
    {
        if (($place['source'] ?? '') !== 'wikidata') {
            return false;
        }
        if (! $this->osm->isLandmarkClass($place['class_id'] ?? null)) {
            return false;
        }

        $modelCode = $this->confidence->normalizeCountryCode(
            isset($data['country']) && is_string($data['country']) ? $data['country'] : null
        );
        $placeCode = $this->confidence->normalizeCountryCode(
            isset($place['country_code']) && is_string($place['country_code']) ? $place['country_code'] : null
        );

        if ($modelCode === null || $placeCode === null || $modelCode !== $placeCode) {
            return false;
        }

        return true;
    }

    private function isIdentified(array $data): bool
    {
        $name = strtolower(trim((string) ($data['landmark_name'] ?? '')));

        return $name !== ''
            && ! in_array($name, ['unknown', 'unknown location', 'n/a', 'none'], true);
    }

    /**
     * location_precision: landmark (gazetteer-backed), approximate (model
     * estimate, settlement / region / natural feature or compound ambiguity)
     * or region (no identifiable precise place).
     */
    private function resolveLocationPrecision(array $data): string
    {
        if (! $this->isIdentified($data)) {
            return 'region';
        }
        $gazetteerLandmark = ($data['coordinate_source'] ?? null) === 'gazetteer'
            && ! empty($data['gazetteer_landmark_class']);
        if ($gazetteerLandmark) {
            return 'landmark';
        }
        if ($this->hasAreaLevelName($data)) {
            // Settlement / region / natural feature / landmark_name == city:
            // an area, not a pin. Never a "landmark" precision here.
            return 'approximate';
        }
        if (($data['coordinate_source'] ?? null) === 'gazetteer') {
            return 'landmark';
        }
        if (isset($data['latitude'], $data['longitude']) && $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            return 'approximate';
        }
        if ((float) ($data['coordinate_disagreement_km'] ?? 0) > 0) {
            // Model vs gazetteer disagreement: keep the estimate, mark it approximate.
            return 'approximate';
        }

        return 'region';
    }

    /**
     * Uncertainty radius in meters for the returned precision. Never zero:
     * a matched landmark has a 100 m minimum, a model-only estimate carries a
     * conservative 5 km default, an unidentified answer is region-level, and
     * area-level answers (settlement / region / natural feature) carry at least
     * AREA_RADIUS_M.
     */
    private function resolveUncertaintyRadius(array $data): float
    {
        if (! $this->isIdentified($data)) {
            return 50_000.0;
        }
        if ($this->hasAreaLevelName($data)) {
            return max(self::AREA_RADIUS_M, 5_000.0);
        }
        if (($data['coordinate_source'] ?? null) === 'gazetteer') {
            // Matched landmark via gazetteer: 100 m minimum (documented floor),
            // widened to the gazetteer disagreement distance when one was kept.
            $disagreementKm = (float) ($data['coordinate_disagreement_km'] ?? 0);

            return max(self::MIN_GAZETTEER_RADIUS_M, $disagreementKm > 0 ? $disagreementKm * 1000 : 0.0);
        }
        if (isset($data['latitude'], $data['longitude']) && $this->isValidCoordinate($data['latitude'], $data['longitude'])) {
            // Model-only estimate: conservative 5 km default (documented).
            return 5_000.0;
        }

        return 50_000.0;
    }

    /**
     * Phase-2 area-level detection: the landmark name equals the city (the
     * Alpine landscape case, e.g. "San Vigilio di Marebbe") or the name is a
     * settlement / region / natural feature. Those are areas, not pins.
     */
    private function hasAreaLevelName(array $data): bool
    {
        $name = strtolower(trim((string) ($data['landmark_name'] ?? '')));
        $city = strtolower(trim((string) ($data['city'] ?? '')));

        if ($name === '' || $city === '') {
            return false;
        }
        if ($name === $city) {
            // The landmark name carries no distinct place type: it is the place.
            return true;
        }

        $areaMarkers = [
            'village', 'town', 'city', 'municipality', 'district', 'province', 'state', 'region',
            'county', 'mountain', 'peak', 'hill', 'valley', 'ridge', 'alps', 'alpine', 'island', 'islands',
            'peninsula', 'archipelago', 'coast', 'beach', 'shore', 'river', 'rivers', 'lake', 'lakes',
            'sea', 'ocean', 'bay', 'gulf', 'straits', 'forest', 'forests', 'park', 'national_park',
            'reserve', 'wilderness', 'desert', 'plain', 'plateau', 'canyon', 'glacier', 'volcano',
            'waterfall', 'waterfalls', 'moor', 'heath', 'moorland', 'savanna', 'marsh', 'wetland',
            'cave', 'caverns', 'hot_spring', 'geothermal', 'delta', 'estuary', 'lagoon', 'fjord', 'strait',
        ];

        foreach ($areaMarkers as $marker) {
            if ($name === $marker || str_ends_with($name, ' '.$marker) || str_ends_with($name, '_'.$marker)) {
                return true;
            }
        }

        // "The ..." prefixed settlements read as area-level too.
        if (preg_match('/^(the\s+)?(village|town|city|municipality|region|province|state|district|county|mountain|peak|hill|valley|river|lake|sea|ocean|island|coast|beach|forest|park|national_park|reserve|desert|plateau|glacier|volcano|wilderness)\b/i', $name) === 1) {
            return true;
        }

        return false;
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
            'uncertainty_radius_m' => 50_000.0,
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
