<?php

namespace App\Services\Gemini;

use App\Models\Landmark;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GeminiResultEnhancer
{
    public function __construct(private ConfidenceCalculator $confidence) {}

    /**
     * RUN THE ENRICHMENT CHAIN + FIELD VALIDATION.
     * Confidence is computed from checkable signals by ConfidenceCalculator,
     * never from the model's self-reported number.
     */
    public function enhance($data, array $metadata = [])
    {
        $data = $this->databaseCrossReference($data);
        $data = $this->multiSourceVerification($data);
        $data = $this->confidence->calculate($data, $metadata);
        $data = $this->addRichMetadata($data);

        return $this->validateAllFields($data);
    }

    private function isValidCoordinate($lat, $lng)
    {
        return is_numeric($lat) && is_numeric($lng) && abs($lat) <= 90 && abs($lng) <= 180 && $lat != 0 && $lng != 0;
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
