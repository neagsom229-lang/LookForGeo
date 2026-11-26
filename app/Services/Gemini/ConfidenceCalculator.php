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

    /**
     * A named landmark with valid coordinates must never score below the
     * no-coordinate cap: having a pin is never worse evidence than not
     * having one. The same value doubles as the floor for identified
     * landmarks whose raw signal sum is lower.
     */
    private const IDENTIFIED_COORDINATE_FLOOR = self::NO_COORDINATE_CAP;

    /**
     * Independent forward geocode agreement. "A few km" is deliberately
     * tight: a landmark name that geocodes to the same country within this
     * radius is real corroboration, not a loose text match.
     */
    private const FORWARD_GEOCODE_AGREE_KM = 5.0;

    private const FORWARD_GEOCODE_POINTS = 10;

    /**
     * Two-model agreement. Only usable when a second model already produced
     * an answer (never by making an extra call for this signal). The same
     * landmark name and coordinates within a few km is independent evidence:
     * different models do not share the same weights or prompt sampling.
     */
    private const MODEL_AGREEMENT_KM = 5.0;

    private const MODEL_AGREEMENT_POINTS = 10;

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

        // Coordinates obtained by forward-geocoding the model's OWN landmark
        // name are not independent evidence: they must not corroborate that
        // same answer via country / distance / source-count points.
        $selfSourced = ($data['coordinate_source'] ?? null) === 'forward_geocode';

        $this->checkExif($data, $metadata, $signals, $factors);
        $this->checkCountry($data, $signals, $factors, $selfSourced);
        $this->checkForwardGeocode($data, $signals, $factors, $selfSourced);
        $this->checkVisualCues($data, $signals, $factors);
        $this->checkResolution($metadata, $signals, $factors);
        $this->checkCandidates($data, $signals, $factors);
        $this->checkCoordinateSources($data, $signals, $factors, $selfSourced);
        $this->checkModelAgreement($data, $signals, $factors);

        return $this->finalize($data, $signals, $factors, $modelReported);
    }

    /**
     * Add a second model's answer after the fact (the escalation pass already
     * ran). Recomputes only the agreement signal and the final score; it never
     * calls a model or a geocoder.
     */
    public function withSecondaryModelAnswer(array $data, array $secondary): array
    {
        $data['secondary_model_answer'] = $secondary;

        $signals = is_array($data['confidence_signals'] ?? null) ? $data['confidence_signals'] : [];
        $factors = is_array($data['confidence_factors'] ?? null) ? $data['confidence_factors'] : [];

        unset($signals['model_agreement']);
        // Drop factors produced by the previous finalize() pass so a second
        // pass cannot duplicate them.
        $factors = array_values(array_filter($factors, function ($factor) {
            return ! is_string($factor)
                || (! str_contains($factor, 'score floored at ')
                    && ! str_contains($factor, 'Two models independently agreed')
                    && ! str_contains($factor, 'Second model answer differs')
                    && ! str_contains($factor, 'Both answers came from the same model')
                    && ! str_contains($factor, 'Second model answer is missing')
                    && ! str_contains($factor, 'Only one model answered')
                    && ! str_contains($factor, 'At least one model answer used'));
        }));

        $this->checkModelAgreement($data, $signals, $factors);

        $modelReported = isset($data['model_reported_confidence']) && is_numeric($data['model_reported_confidence'])
            ? (int) $data['model_reported_confidence']
            : 0;

        return $this->finalize($data, $signals, $factors, $modelReported);
    }

    /**
     * Apply the cap/floor rules and publish the calibrated fields. Shared by
     * the normal calculation and the post-escalation agreement update so both
     * paths enforce exactly the same rules.
     *
     * @param  array<int,array<string,mixed>>  $signals
     * @param  array<int,string>  $factors
     */
    private function finalize(array $data, array $signals, array $factors, int $modelReported): array
    {
        $score = (int) max(0, min(self::MAX_SCORE, array_sum(array_column($signals, 'points'))));

        // A completed analysis with no usable coordinates is still useful as
        // text, but it must never be presented as a pinned/identified place.
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $hasCoordinates = is_numeric($lat) && is_numeric($lng)
            && abs((float) $lat) <= 90 && abs((float) $lng) <= 180;
        $data['coordinates_missing'] = ! $hasCoordinates;
        $identified = $this->isIdentifiedLandmark($data);
        $floorApplied = false;
        if (! $hasCoordinates) {
            // A named landmark was genuinely identified: present it at the
            // honest cap, not at 0/5 just because there is no pin to verify.
            // An "Unknown Location" answer stays low.
            $score = $identified
                ? self::NO_COORDINATE_CAP
                : min($score, self::NO_COORDINATE_CAP);
            $factors[] = 'Coordinates missing: location could not be pinned; confidence capped at '.self::NO_COORDINATE_CAP.'.';
        } elseif ($identified && $score < self::IDENTIFIED_COORDINATE_FLOOR) {
            // Monotonicity: a named landmark WITH a valid pin must never
            // score lower than the same landmark without one. Contradicting
            // signals still surface in confidence_factors; they cannot push
            // the score below the no-coordinate cap. This is a rule, not
            // evidence, so the result says so explicitly.
            $score = self::IDENTIFIED_COORDINATE_FLOOR;
            $floorApplied = true;
            $factors[] = 'Named landmark with valid coordinates: score floored at '
                .self::IDENTIFIED_COORDINATE_FLOOR.' so a pinned result is never weaker than an unpinned one.';
        }

        $data['model_reported_confidence'] = $modelReported;
        $data['confidence'] = $score;
        $data['confidence_floor_applied'] = $floorApplied;
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

    private function checkCountry(array &$data, array &$signals, array &$factors, bool $selfSourced = false): void
    {
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $country = strtolower((string) ($data['country'] ?? ''));

        if ($selfSourced) {
            $signals['country_consistency'] = ['points' => 0, 'state' => 'self_sourced'];
            $factors[] = 'Country was matched by the forward geocode of the model name; no independent corroboration.';

            return;
        }

        if (! is_numeric($lat) || ! is_numeric($lng) || $country === '' || $country === 'unknown') {
            $signals['country_consistency'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'No country / coordinates to verify with reverse geocoding.';
            $this->recordGeocodeAttempt($data, $lat, $lng, 'skipped_no_coordinates', [
                'reverse_country' => null,
                'reason' => 'missing model coordinates or country',
            ]);

            return;
        }

        $reverseName = null;
        $reverseCode = null;
        $attemptRecorded = false;
        if (! config('services.gemini.confidence_reverse_geocode', true)) {
            $this->recordGeocodeAttempt($data, $lat, $lng, 'disabled', ['reverse_country' => null]);
            $attemptRecorded = true;
        } else {
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

        $outcome = $this->reverseOutcome();
        if ($reverseName !== null) {
            $outcome = isset($data['matched_landmark'])
                && $this->reverseOutcome() === null
                ? 'landmark_db_fallback'
                : ($outcome ?: 'ok');
        }
        if (! $attemptRecorded) {
            $this->recordGeocodeAttempt($data, $lat, $lng, $outcome ?: 'no_country_data', [
                'reverse_country' => $reverseName,
                'reverse_code' => $reverseCode,
            ]);
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

    private function checkCoordinateSources(array $data, array &$signals, array &$factors, bool $selfSourced = false): void
    {
        if ($selfSourced) {
            $signals['coordinate_sources'] = ['points' => 0, 'count' => 0, 'state' => 'self_sourced'];
            $factors[] = 'Only the forward geocode produced these coordinates; no independent sources to agree.';

            return;
        }

        $sources = (int) ($data['coordinate_sources'] ?? 0);
        $points = $sources >= 2 ? 10 : 0;
        $signals['coordinate_sources'] = ['points' => $points, 'count' => $sources];
        $factors[] = $points > 0
            ? "{$sources} independent coordinate sources agree."
            : 'Only one coordinate source available.';
    }

    /**
     * Independent agreement between two models that both ran. This is only
     * read from data already present (`secondary_model_answer`); it never
     * triggers a second model call. Same-model answers are not independent.
     */
    private function checkModelAgreement(array $data, array &$signals, array &$factors): void
    {
        $secondary = $data['secondary_model_answer'] ?? null;
        if (! is_array($secondary)) {
            $signals['model_agreement'] = ['points' => 0, 'state' => 'unavailable'];
            $factors[] = 'Only one model answered; no second answer to compare.';

            return;
        }

        $primaryModel = isset($data['winner']) ? (string) $data['winner'] : null;
        $secondaryModel = isset($secondary['model']) ? (string) $secondary['model'] : null;
        if ($primaryModel !== null && $secondaryModel !== null && $primaryModel === $secondaryModel) {
            $signals['model_agreement'] = ['points' => 0, 'state' => 'same_model'];
            $factors[] = 'Both answers came from the same model; agreement is not independent.';

            return;
        }

        $primaryName = $this->normalizeLandmarkName($data['landmark_name'] ?? null);
        $secondaryName = $this->normalizeLandmarkName($secondary['landmark_name'] ?? null);

        // Coordinates produced by forward-geocoding a model's own name are
        // not independent: comparing them would only re-test the name match.
        if (($data['coordinate_source'] ?? null) === 'forward_geocode'
            || ($secondary['coordinate_source'] ?? null) === 'forward_geocode') {
            $signals['model_agreement'] = ['points' => 0, 'state' => 'self_sourced'];
            $factors[] = 'At least one model answer used forward-geocoded coordinates; agreement would be circular.';

            return;
        }

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        $secondaryLat = $secondary['latitude'] ?? null;
        $secondaryLng = $secondary['longitude'] ?? null;

        if ($primaryName === '' || $secondaryName === ''
            || ! is_numeric($lat) || ! is_numeric($lng)
            || ! is_numeric($secondaryLat) || ! is_numeric($secondaryLng)) {
            $signals['model_agreement'] = ['points' => 0, 'state' => 'incomplete'];
            $factors[] = 'Second model answer is missing a landmark name or valid coordinates; no agreement points.';

            return;
        }

        $distanceKm = $this->haversine(
            (float) $lat, (float) $lng, (float) $secondaryLat, (float) $secondaryLng
        );
        $namesMatch = $primaryName === $secondaryName;
        $agrees = $namesMatch && $distanceKm <= self::MODEL_AGREEMENT_KM;
        $points = $agrees ? self::MODEL_AGREEMENT_POINTS : 0;

        $signals['model_agreement'] = [
            'points' => $points,
            'state' => $agrees ? 'agree' : 'disagree',
            'distance_km' => round($distanceKm, 1),
            'names_match' => $namesMatch,
            'primary_model' => $primaryModel,
            'secondary_model' => $secondaryModel,
        ];
        $factors[] = $agrees
            ? 'Two models independently agreed on "'.$primaryName.'" within '
                .round($distanceKm, 1).' km: +'.self::MODEL_AGREEMENT_POINTS.'.'
            : 'Second model answer differs (name match: '.($namesMatch ? 'yes' : 'no')
                .', '.round($distanceKm, 1).' km away); no agreement points.';
    }

    private function normalizeLandmarkName($value): string
    {
        $name = strtolower(trim((string) $value));
        $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        $name = preg_replace('/^the\s+/', '', $name) ?? $name;

        return $name;
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

    /**
     * A non-empty landmark name that is not one of the model's "I don't
     * know" sentinels. Used for both the no-coordinate cap and the
     * identified-landmark floor so the two rules cannot drift apart.
     */
    private function isIdentifiedLandmark(array $data): bool
    {
        $name = strtolower(trim((string) ($data['landmark_name'] ?? '')));

        return $name !== ''
            && ! in_array($name, ['unknown', 'unknown location', 'n/a', 'none'], true);
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

    private function recordGeocodeAttempt(array &$data, $lat, $lng, string $outcome, array $extra = []): void
    {
        $data['geocode_attempt'] = array_merge([
            'coordinates_used' => [
                is_numeric($lat) ? (float) $lat : null,
                is_numeric($lng) ? (float) $lng : null,
            ],
            'outcome_category' => $outcome,
        ], $extra);
    }

    /** The OSM service records the last geocode outcome; mocks may not. */
    private function reverseOutcome(): ?string
    {
        try {
            return $this->osm->lastOutcomeCategory();
        } catch (\Throwable $e) {
            // Mocked/older services may not expose the outcome.
            return null;
        }
    }

    /**
     * Independent forward geocode of the landmark name. Model-supplied
     * coordinates earn points only when they land within a few km of that
     * independent result IN THE SAME COUNTRY. Coordinates that came from
     * the forward geocode itself are circular evidence and always earn 0.
     */
    private function checkForwardGeocode(array $data, array &$signals, array &$factors, bool $selfSourced = false): void
    {
        $signals['forward_geocode_distance'] = ['points' => 0, 'state' => $selfSourced ? 'self_sourced' : 'unavailable'];
        if ($selfSourced) {
            $factors[] = 'Coordinates are themselves the forward geocode result; distance check would be circular.';

            return;
        }
        if (! config('services.gemini.confidence_forward_geocode', true)) {
            $factors[] = 'Forward-geocode distance check is disabled.';

            return;
        }

        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            $factors[] = 'No coordinates for the forward-geocode distance check.';

            return;
        }

        $query = trim(implode(', ', array_filter([
            $data['landmark_name'] ?? null,
            $data['city'] ?? null,
            $data['country'] ?? null,
        ], fn ($part) => is_string($part) && trim($part) !== '')));
        if ($query === '') {
            $factors[] = 'No place name for the forward-geocode distance check.';

            return;
        }

        try {
            $place = $this->osm->forwardGeocode(
                (string) ($data['landmark_name'] ?? ''),
                isset($data['city']) ? (string) $data['city'] : null,
                isset($data['country']) ? (string) $data['country'] : null
            );
        } catch (\Throwable $e) {
            $place = null;
        }

        if (! is_array($place) || ! isset($place['lat'], $place['lng'])) {
            $factors[] = 'Forward geocode did not resolve a location for distance comparison.';

            return;
        }

        $distanceKm = $this->haversine(
            (float) $lat, (float) $lng, (float) $place['lat'], (float) $place['lng']
        );

        // Same country is part of the rule: two places with the same name in
        // different countries must not corroborate each other.
        $statedCode = $this->countryCode($data['country'] ?? null);
        $placeAddress = is_array($place['address'] ?? null) ? $place['address'] : [];
        $placeCode = $this->countryCode(
            $place['country_code'] ?? $placeAddress['country_code'] ?? null
        );
        $sameCountry = $statedCode !== null && $placeCode !== null && $statedCode === $placeCode;

        $agrees = $distanceKm <= self::FORWARD_GEOCODE_AGREE_KM && $sameCountry;
        $points = $agrees ? self::FORWARD_GEOCODE_POINTS : 0;

        $signals['forward_geocode_distance'] = [
            'points' => $points,
            'state' => $agrees ? 'agree' : 'resolved',
            'distance_km' => round($distanceKm, 1),
            'query' => $query,
            'country_match' => $sameCountry,
        ];
        $factors[] = $agrees
            ? 'Model coordinates agree with an independent forward geocode of "'.$query.'" ('
                .round($distanceKm, 1).' km, same country): +'.self::FORWARD_GEOCODE_POINTS.'.'
            : 'Forward geocode of "'.$query.'" is '.round($distanceKm, 1)
                .' km away (same country: '.($sameCountry ? 'yes' : 'no').'); no independent agreement points.';
    }

    /**
     * Resolve a country label (name or ISO 3166 alpha-2) to a canonical code.
     * Nominatim supplies address.country_code; model output uses names such as
     * "USA", so a small alias table is needed before comparing.
     */
    /** Shared ISO-3166 alpha-2 normalisation for the geocountry guard. */
    public function normalizeCountryCode(?string $value): ?string
    {
        return $this->countryCode($value);
    }

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
