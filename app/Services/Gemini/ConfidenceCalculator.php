<?php

namespace App\Services\Gemini;

use App\Services\OpenStreetMapService;
use Illuminate\Support\Facades\Log;
use League\ISO3166\ISO3166;

/**
 * Calibrated confidence from CHECKABLE signals only.
 *
 * The model's self-reported confidence is explicitly NOT used as input:
 * it reported 100 on a 13 KB thumbnail. We keep it on the result as
 * `model_reported_confidence` for comparison, but the displayed score is
 * derived from EXIF agreement, country consistency, cue count, image
 * resolution, candidate separation and coordinate-source agreement.
 *
 * The score never reaches 100: a single source cannot fully verify a
 * geolocation. 95 is the ceiling, and anything below 50 is low-confidence.
 */
class ConfidenceCalculator
{
    /** ISO 3166 data provider, built once; handles official country names. */
    private static ?ISO3166 $isoCountries = null;

    private const MAX_SCORE = 95;

    private const LOW_CONFIDENCE_BELOW = 50;

    /** A result without coordinates cannot be pinned: cap its confidence. */
    private const NO_COORDINATE_CAP = 49;

    public function __construct(private OpenStreetMapService $osm) {}

    /**
     * @param  array<string,mixed>  $data  enriched model result
     * @param  array<string,mixed>  $metadata  image metadata (gps, resolution, ...)
     * @return array<string,mixed> data with calibrated confidence fields
     */
    public function calculate(array $data, array $metadata = []): array
    {
        $modelReported = isset($data['confidence']) && is_numeric($data['confidence'])
            ? (int) $data['confidence']
            : 0;

        $signals = [];
        $factors = [];

        $this->checkExif($data, $metadata, $signals, $factors);
        $this->checkCountry($data, $signals, $factors);
        $this->checkVisualCues($data, $signals, $factors);
        $this->checkResolution($metadata, $signals, $factors);
        $this->checkCandidates($data, $signals, $factors);
        $this->checkCoordinateSources($data, $signals, $factors);

        $score = (int) max(0, min(self::MAX_SCORE, array_sum(array_column($signals, 'points'))));

        // A completed analysis with no usable coordinates is still useful as
        // text, but it must never be presented as a pinned/identified place.
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $hasCoordinates = is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
        $data['coordinates_missing'] = ! $hasCoordinates;
        if (! $hasCoordinates) {
            $score = min($score, self::NO_COORDINATE_CAP);
            $factors[] = 'Coordinates missing: location could not be pinned; confidence capped at '.self::NO_COORDINATE_CAP.'.';
        }

        $data['model_reported_confidence'] = $modelReported;
        $data['confidence'] = $score;
        $data['confidence_signals'] = $signals;
        $data['confidence_factors'] = $factors;
        $data['confidence_basis'] = 'calibrated-signals';
        $data['confidence_level'] = $this->level($score);
        $data['low_confidence'] = $score < self::LOW_CONFIDENCE_BELOW;

        // Always surface up to three alternatives when we are not sure.
        $data['alternatives'] = $data['low_confidence']
            ? $this->topCandidates($data)
            : [];

        return $data;
    }

    private function checkExif(array $data, array $metadata, array &$signals, array &$factors): void
    {
        $exif = $metadata['gps'] ?? null;
        $modelLat = $data['latitude'] ?? null;
        $modelLng = $data['longitude'] ?? null;

        if (! is_array($exif) || ! is_numeric($modelLat) || ! is_numeric($modelLng)
            || ! isset($exif['latitude'], $exif['longitude'])) {
            $signals['exif_gps'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'No EXIF GPS to cross-check the model coordinates.';

            return;
        }

        $distanceKm = $this->haversine(
            (float) $exif['latitude'], (float) $exif['longitude'],
            (float) $modelLat, (float) $modelLng
        );
        $points = $distanceKm <= 25 ? 25 : ($distanceKm <= 100 ? 5 : -15);
        $signals['exif_gps'] = [
            'points' => $points,
            'state' => $distanceKm <= 25 ? 'agree' : ($distanceKm <= 100 ? 'near' : 'conflict'),
            'distance_km' => round($distanceKm, 1),
        ];
        $factors[] = $points > 0
            ? 'EXIF GPS agrees with the model location ('.round($distanceKm, 1).' km).'
            : 'EXIF GPS conflicts with the model location ('.round($distanceKm, 1).' km).';
    }

    private function checkCountry(array $data, array &$signals, array &$factors): void
    {
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $country = strtolower((string) ($data['country'] ?? ''));

        if (! is_numeric($lat) || ! is_numeric($lng) || $country === '' || $country === 'unknown') {
            $signals['country_consistency'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'No country / coordinates to verify with reverse geocoding.';

            return;
        }

        $reverseName = null;
        $reverseCode = null;
        if (config('services.gemini.confidence_reverse_geocode', true)) {
            try {
                $place = $this->osm->reverseGeocode((float) $lat, (float) $lng);
                if (is_array($place)) {
                    // Real Nominatim nests country data under "address".
                    $address = is_array($place['address'] ?? null) ? $place['address'] : [];
                    $reverseCode = $this->countryCode($address['country_code'] ?? $place['country_code'] ?? null);
                    $reverseName = strtolower((string) (
                        $address['country'] ?? $place['country'] ?? ''
                    ));
                    if ($reverseCode === null) {
                        $reverseCode = $this->countryCode($reverseName);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Reverse-geocode confidence check failed: '.$e->getMessage());
            }
        }

        $statedCode = $this->countryCode($country);

        // Landmark DB match is the offline fallback for the same signal.
        if ($reverseCode === null && $reverseName === null && isset($data['matched_landmark'])) {
            $reverseCode = $statedCode;
            $reverseName = $country; // DB match already cross-referenced country
        }

        // An empty stated country already returned above.
        if ($reverseCode === null && $reverseName === null) {
            $signals['country_consistency'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'Could not verify that the coordinates are inside the stated country.';

            return;
        }

        // Prefer ISO 3166 alpha-2 codes; fall back to normalised names only
        // when either side cannot be resolved to a code.
        $method = ($statedCode !== null && $reverseCode !== null) ? 'iso' : 'name';
        if ($method === 'iso') {
            $matches = $statedCode === $reverseCode;
        } else {
            $matches = $reverseName !== null
                && (str_contains($reverseName, $country) || str_contains($country, $reverseName));
        }
        $points = $matches ? 20 : -20;
        $signals['country_consistency'] = [
            'points' => $points,
            'state' => $matches ? 'match' : 'mismatch',
            'method' => $method,
            'stated' => $country,
            'stated_code' => $statedCode,
            'reverse_geocoded' => $reverseName,
            'reverse_code' => $reverseCode,
        ];
        $factors[] = $matches
            ? 'Coordinates are inside the stated country ('.$country.($method === 'iso' ? ', '.$statedCode : '').').'
            : "Country mismatch: model says {$country}, reverse geocode says ".($reverseName ?: $reverseCode).'.';
    }

    private function checkVisualCues(array $data, array &$signals, array &$factors): void
    {
        $count = is_array($data['visual_clues'] ?? null) ? count($data['visual_clues']) : 0;
        $points = $count >= 4 ? 10 : ($count >= 2 ? 5 : 0);
        $signals['visual_cues'] = ['points' => $points, 'count' => $count];
        $factors[] = $points > 0
            ? "{$count} independent visual cues returned."
            : 'Fewer than two visual cues returned.';
    }

    private function checkResolution(array $metadata, array &$signals, array &$factors): void
    {
        $shortEdge = null;
        if (is_array($metadata['resolution'] ?? null)) {
            $r = $metadata['resolution'];
            $shortEdge = min((int) ($r['width'] ?? 0), (int) ($r['height'] ?? 0));
        }

        if (! $shortEdge) {
            $signals['resolution'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'Image resolution not available for calibration.';

            return;
        }

        $points = $shortEdge >= 720 ? 10 : ($shortEdge >= 480 ? 5 : -10);
        $signals['resolution'] = ['points' => $points, 'short_edge_px' => $shortEdge];
        $factors[] = $points >= 0
            ? "Image short edge {$shortEdge} px is sufficient for visual clues."
            : "Low-resolution input ({$shortEdge} px short edge) reduces reliability.";
    }

    private function checkCandidates(array $data, array &$signals, array &$factors): void
    {
        $probs = [];
        foreach (($data['candidates'] ?? []) as $candidate) {
            if (is_array($candidate) && isset($candidate['probability']) && is_numeric($candidate['probability'])) {
                $probs[] = (int) $candidate['probability'];
            }
        }

        if (count($probs) < 2) {
            $signals['candidate_gap'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'No candidate probabilities to measure internal agreement.';

            return;
        }

        rsort($probs);
        $gap = $probs[0] - $probs[1];
        $points = $gap > 50 ? 15 : ($gap >= 25 ? 8 : -15);
        $signals['candidate_gap'] = ['points' => $points, 'gap' => $gap];
        $factors[] = $points > 0
            ? "Top candidate leads runner-up by {$gap} points."
            : "Top two candidates nearly tied (gap {$gap}); location is ambiguous.";
    }

    private function checkCoordinateSources(array $data, array &$signals, array &$factors): void
    {
        $sources = (int) ($data['coordinate_sources'] ?? 0);
        $points = $sources >= 2 ? 10 : 0;
        $signals['coordinate_sources'] = ['points' => $points, 'count' => $sources];
        $factors[] = $points > 0
            ? "{$sources} independent coordinate sources agree."
            : 'Only one coordinate source available.';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function topCandidates(array $data): array
    {
        $candidates = $data['candidates'] ?? [];
        if (! is_array($candidates)) {
            return [];
        }

        usort($candidates, function ($a, $b) {
            return (int) ($b['probability'] ?? 0) <=> (int) ($a['probability'] ?? 0);
        });

        return array_slice($candidates, 0, 3);
    }

    private function level(int $score): string
    {
        if ($score >= 80) {
            return 'Very High';
        }
        if ($score >= 65) {
            return 'High';
        }
        if ($score >= 50) {
            return 'Medium';
        }
        if ($score >= 30) {
            return 'Low';
        }

        return 'Very Low';
    }

    /**
     * Resolve a country label (name or ISO 3166 alpha-2) to a canonical code.
     * Nominatim supplies address.country_code; model output uses names such as
     * "USA", so a small alias table is needed before comparing.
     */
    private function countryCode(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if ($value === '') {
            return null;
        }
        // Two-letter non-ISO aliases must be canonicalised before the regex.
        if ($value === 'uk') {
            return 'gb';
        }
        if (preg_match('/^[a-z]{2}$/', $value)) {
            return $value;
        }

        $aliases = [
            'usa' => 'us', 'u.s.a.' => 'us', 'united states' => 'us',
            'united states of america' => 'us', 'america' => 'us',
            'uk' => 'gb', 'u.k.' => 'gb', 'united kingdom' => 'gb',
            'great britain' => 'gb', 'england' => 'gb',
            'uae' => 'ae', 'united arab emirates' => 'ae',
            'russia' => 'ru', 'russian federation' => 'ru',
            'south korea' => 'kr', 'republic of korea' => 'kr',
            'north korea' => 'kp', 'viet nam' => 'vn',
            'lao peoples democratic republic' => 'la',
            'czech republic' => 'cz', 'ivory coast' => 'ci',
            'france' => 'fr', 'germany' => 'de', 'italy' => 'it',
            'spain' => 'es', 'portugal' => 'pt', 'netherlands' => 'nl',
            'belgium' => 'be', 'switzerland' => 'ch', 'austria' => 'at',
            'canada' => 'ca', 'mexico' => 'mx', 'brazil' => 'br',
            'argentina' => 'ar', 'chile' => 'cl', 'peru' => 'pe',
            'china' => 'cn', 'japan' => 'jp', 'india' => 'in',
            'indonesia' => 'id', 'thailand' => 'th', 'malaysia' => 'my',
            'singapore' => 'sg', 'philippines' => 'ph', 'pakistan' => 'pk',
            'bangladesh' => 'bd', 'sri lanka' => 'lk', 'nepal' => 'np',
            'cambodia' => 'kh', 'myanmar' => 'mm', 'burma' => 'mm',
            'australia' => 'au', 'new zealand' => 'nz',
            'egypt' => 'eg', 'morocco' => 'ma', 'tunisia' => 'tn',
            'south africa' => 'za', 'kenya' => 'ke', 'nigeria' => 'ng',
            'turkey' => 'tr', 'turkiye' => 'tr', 'poland' => 'pl',
            'sweden' => 'se', 'norway' => 'no', 'denmark' => 'dk',
            'finland' => 'fi', 'ireland' => 'ie', 'greece' => 'gr',
        ];

        if (isset($aliases[$value])) {
            return $aliases[$value];
        }

        // league/iso3166 resolves official names case-insensitively and is
        // used behind this normaliser (weights never depend on the source).
        try {
            self::$isoCountries ??= new ISO3166;

            return strtolower(self::$isoCountries->name($value)['alpha2']);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(min(1, sqrt($a)));
    }
}
