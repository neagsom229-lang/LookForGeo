<?php

namespace App\Services\Gemini;

use App\Models\Landmark;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class GeminiResultEnhancer
{
    /**
     * RUN THE ENRICHMENT CHAIN + FIELD VALIDATION.
     * Equivalent to the previous GeminiService::ultimateEnrichment +
     * validateAllFields sequence, now isolated for reuse and testing.
     */
    public function enhance($data)
    {
        $data = $this->databaseCrossReference($data);
        $data = $this->multiSourceVerification($data);
        $data = $this->calculateUltimateConfidence($data);
        $data = $this->calibrateConfidenceWithCandidates($data);
        $data = $this->addRichMetadata($data);
        $data = $this->humanizeLabels($data);

        return $this->validateAllFields($data);
    }

    /**
     * Adjust confidence based on how close the top candidate is to the
     * runner-up. A model that names one landmark but whose own second
     * candidate is nearly as likely is telling you the image is
     * genuinely ambiguous — the earlier boost logic had no way to see
     * that, since it only looked at the single reported confidence.
     */
    private function calibrateConfidenceWithCandidates($data)
    {
        $candidates = $data['candidates'] ?? [];
        if (! is_array($candidates) || count($candidates) < 2) {
            return $data;
        }

        $probs = array_values(array_filter(array_map(
            fn ($c) => is_numeric($c['probability'] ?? null) ? (int) $c['probability'] : null,
            $candidates
        ), fn ($p) => $p !== null));

        if (count($probs) < 2) {
            return $data;
        }

        rsort($probs);
        $gap = $probs[0] - $probs[1];

        $adjustment = 0;
        $note = null;

        if ($gap < 10) {
            $adjustment = -15;
            $note = 'Top two candidates nearly tied — treated as ambiguous';
        } elseif ($gap < 25) {
            $adjustment = -5;
            $note = 'Runner-up candidate still plausible';
        } elseif ($gap > 50) {
            $adjustment = 5;
            $note = 'Clear separation from alternate candidates';
        }

        if ($adjustment !== 0) {
            $data['confidence'] = (int) max(0, min(100, ($data['confidence'] ?? 0) + $adjustment));
            $factors = $data['confidence_factors'] ?? [];
            $factors[] = $note;
            $data['confidence_factors'] = $factors;
        }

        $data['candidate_gap'] = $gap;

        return $data;
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
                $data['region'] = $landmark->region ?? $data['region'];
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
     * ULTIMATE CONFIDENCE CALCULATION
     */
    private function calculateUltimateConfidence($data)
    {
        $base = $data['confidence'] ?? 0;
        $boost = 0;
        $factors = [];

        if (isset($data['coordinate_sources']) && $data['coordinate_sources'] >= 2) {
            $boost += 15;
            $factors[] = 'Multiple coordinate sources confirmed';
        }

        if (isset($data['matched_landmark'])) {
            $boost += 20;
            $factors[] = 'Exact landmark match in database';
        }

        if (! empty($data['city']) && ! empty($data['country']) && $data['city'] !== 'Unknown' && $data['country'] !== 'Unknown') {
            $boost += 10;
            $factors[] = 'City and country identified';
        }

        if (isset($data['visual_clues']) && count($data['visual_clues']) >= 4) {
            $boost += 10;
            $factors[] = 'Multiple visual clues identified';
        }

        if ($base >= 70) {
            $boost += 5;
            $factors[] = 'AI expressed high confidence';
        }

        // No forced rounding to nearest 5 — that was discarding real
        // signal (e.g. 82 vs 83 landing on the same bucket for no reason).
        $final = (int) min(100, $base + $boost);

        $data['confidence'] = $final;
        $data['confidence_boost'] = $boost;
        $data['confidence_factors'] = $factors;

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
     * HUMANIZE LABELS
     */
    private function humanizeLabels($data)
    {
        if (isset($data['confidence'])) {
            $data['confidence_level'] = $this->getConfidenceLevel($data['confidence']);
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
            'confidence_level' => 'None',
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

    private function getConfidenceLevel($confidence)
    {
        if ($confidence >= 95) {
            return 'Verified - Very High';
        }
        if ($confidence >= 80) {
            return 'Very High';
        }
        if ($confidence >= 65) {
            return 'High';
        }
        if ($confidence >= 50) {
            return 'Medium';
        }
        if ($confidence >= 30) {
            return 'Low';
        }

        return 'Very Low';
    }
}
