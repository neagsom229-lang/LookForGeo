<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use App\Models\Landmark;

class GeminiService
{
    protected $apiKey;
    protected $primaryModel;
    protected $fallbackModel;
    protected $requestTimeout;
    protected $maxRetries;
    protected $maxTotalTimeSeconds;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->primaryModel = config('services.gemini.model', 'gemini-3.6-flash');
        // Fallback should be a *stable, different-generation* model so a
        // primary outage or deprecation doesn't take down both. As of
        // Aug 2026, gemini-3.5-flash is the prior-generation stable Flash.
        $this->fallbackModel = config('services.gemini.fallback_model', 'gemini-3.5-flash');
        $this->requestTimeout = config('services.gemini.timeout', 60);
        $this->maxRetries = config('services.gemini.retries', 2);
        // Hard ceiling on total wall-clock time across primary + fallback +
        // all retries combined. Without this, timeout(60) * retries(2+1)
        // attempts * two models can add up to ~6 minutes — long past
        // PHP-FPM's max_execution_time or a reverse-proxy's read timeout,
        // meaning the client gets a 504 well before this method returns
        // anything, including the graceful fallback response. If this
        // analysis genuinely needs more than ~45s of retry budget in your
        // environment, that's a sign it belongs in a queued job rather
        // than a synchronous request.
        $this->maxTotalTimeSeconds = config('services.gemini.max_total_time', 45);

        Log::info('GeminiService initialized', [
            'key_set' => !empty($this->apiKey),
            'primary' => $this->primaryModel,
            'fallback' => $this->fallbackModel,
            'timeout' => $this->requestTimeout,
            'retries' => $this->maxRetries,
            'max_total_time' => $this->maxTotalTimeSeconds,
        ]);
    }

    /**
     * MAIN ENTRY POINT
     */
    public function analyzeGeolocation($imageData, $metadata = [])
    {
        if (empty($imageData)) {
            return $this->getFallbackResponse('No image data provided.');
        }

        $prompt = $this->buildUltimateGeoPrompt($metadata);
        $result = $this->analyzeImageWithFallback($imageData, $prompt);

        if (!is_array($result) || isset($result['error'])) {
            return $this->getFallbackResponse('AI analysis failed: ' . ($result['message'] ?? 'unknown error'));
        }

        $result = $this->ultimateEnrichment($result);
        $result = $this->validateAllFields($result);

        return $result;
    }

    /**
     * PRIMARY + FALLBACK MODEL (with retries, sharing one time budget)
     */
    private function analyzeImageWithFallback($imageData, $prompt)
    {
        // One deadline shared across primary and fallback attempts, rather
        // than each getting its own independent retry budget — that's
        // what let the original version's worst case run into minutes.
        $deadline = microtime(true) + $this->maxTotalTimeSeconds;

        $result = $this->sendGeminiRequestWithRetry($this->primaryModel, $imageData, $prompt, $deadline);
        if (!isset($result['error'])) {
            return $result;
        }

        if ($result['error'] === 'api_key_missing') {
            return $result;
        }

        if (microtime(true) >= $deadline) {
            Log::warning('Time budget exhausted before fallback attempt', ['primary_error' => $result['message']]);
            return $result;
        }

        Log::warning('Primary model failed, using fallback', ['error' => $result['message']]);

        return $this->sendGeminiRequestWithRetry($this->fallbackModel, $imageData, $prompt, $deadline);
    }

    /**
     * SEND REQUEST WITH RETRY LOGIC
     *
     * Retries on connection-level failures (timeout, connection reset)
     * AND on 5xx server errors from Gemini, since those are typically
     * transient too — the original version only retried the former,
     * which misses the more common "Google's endpoint hiccuped" case.
     * Never retries 429 (quota) — a rate limit won't clear in the ~1-4s
     * this backoff window covers, so retrying just burns more of the
     * shared time budget for no benefit.
     */
    private function sendGeminiRequestWithRetry($model, $imageData, $prompt, float $deadline)
    {
        $backoffMicros = 1_000_000; // start at 1s
        $maxBackoffMicros = (int) config('services.gemini.max_backoff_ms', 5000) * 1000;
        $attempt = 0;
        $lastResult = ['error' => 'unknown', 'message' => 'Request never attempted'];

        while (true) {
            $attempt++;

            if (microtime(true) >= $deadline) {
                Log::warning("Gemini [{$model}] time budget exhausted before attempt {$attempt}");
                return $lastResult;
            }

            $lastResult = $this->sendSingleGeminiRequest($model, $imageData, $prompt);

            if (!isset($lastResult['error'])) {
                return $lastResult;
            }

            $retryable = in_array($lastResult['error'], ['timeout', 'connection_error'], true)
                || ($lastResult['error'] === 'api_error' && ($lastResult['status'] ?? 0) >= 500);

            if (!$retryable || $attempt > $this->maxRetries) {
                return $lastResult;
            }

            // Don't sleep past the deadline just to make an attempt we know we'll skip.
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return $lastResult;
            }
            $sleepMicros = (int) min($backoffMicros, $remaining * 1_000_000);

            Log::warning("Gemini [{$model}] attempt {$attempt} failed, retrying in " . round($sleepMicros / 1_000_000, 1) . 's', [
                'error' => $lastResult['message'],
            ]);

            usleep(max(0, $sleepMicros));
            // Full jitter would be more correct under real concurrency, but
            // a capped exponential backoff is enough here given the low
            // retry count and shared deadline already bounding worst case.
            $backoffMicros = min($backoffMicros * 2, $maxBackoffMicros);
        }
    }

    /**
     * SEND A SINGLE REQUEST TO GEMINI (no retry logic — that lives in the caller)
     */
    private function sendSingleGeminiRequest($model, $imageData, $prompt)
    {
        try {
            if (empty($this->apiKey)) {
                return ['error' => 'api_key_missing', 'message' => 'Gemini API key not configured'];
            }

            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";

            $imageData = $this->compressImageIfNeeded($imageData);

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                            [
                                'inline_data' => [
                                    'mime_type' => 'image/jpeg',
                                    'data' => base64_encode($imageData),
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 4096,
                    'topP' => 0.95,
                    'topK' => 40,
                ],
            ];

            $response = Http::timeout($this->requestTimeout)->post($url, $payload);

            if ($response->status() === 429) {
                return ['error' => 'quota_exceeded', 'message' => 'API quota exceeded. Try again later.', 'status' => 429];
            }

            if (!$response->successful()) {
                Log::error("Gemini API error (status {$response->status()}): " . $response->body());
                return ['error' => 'api_error', 'message' => "HTTP {$response->status()}", 'status' => $response->status()];
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                return ['error' => 'empty_response', 'message' => 'Model returned no text content'];
            }

            $text = $this->cleanText($text);

            $parsed = $this->parseAIResponse($text);
            if (!$parsed) {
                return ['error' => 'parse_error', 'message' => 'Could not parse AI response.'];
            }

            return $this->cleanArray($parsed);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('Gemini connection/timeout error: ' . $e->getMessage());
            return ['error' => 'timeout', 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            Log::error('Gemini request error: ' . $e->getMessage());
            return ['error' => 'connection_error', 'message' => $e->getMessage()];
        }
    }

    /**
     * ULTIMATE GEOLOCATION PROMPT
     */
    private function buildUltimateGeoPrompt($metadata)
    {
        $exif = $this->formatExifForPrompt($metadata);

        return <<<PROMPT
You are a world-class OSINT geolocation analyst with encyclopedic knowledge of Earth's geography, landmarks, architecture, vegetation, climate, and cultures.

**MISSION:** Identify the EXACT location where this image was taken.

**STEP 1: OBSERVE ALL VISUAL CLUES** (analyze these categories):

1. **Architecture & Urban Design**
   - Roof shapes (flat, pitched, tile, thatch)
   - Building materials (brick, concrete, wood, stone)
   - Windows styles, doors, balconies
   - Street furniture (lampposts, benches, manhole covers)
   - Road markings, sidewalk patterns

2. **Vegetation & Environment**
   - Tree species (palm, pine, deciduous, tropical)
   - Crop types if rural
   - Soil colour, rocks, water bodies
   - Climate indicators (snow, desert, lush green)

3. **Infrastructure & Transport**
   - Road signs, traffic lights, street names (if readable)
   - License plate colours/styles
   - Vehicle brands, taxi colours
   - Rail lines, bridges, tunnels

4. **Language & Text**
   - Any text on signs, advertisements, storefronts
   - Script (Latin, Cyrillic, Chinese, Arabic, etc.)
   - If text is readable, capture the exact words.

5. **People & Culture**
   - Clothing styles, headwear
   - Activities (fishing, farming, street market)
   - Skin tones, ethnic diversity

6. **Natural Features**
   - Mountains, rivers, coastline, skyline
   - Cloud types, lighting direction (sun angle hints at hemisphere)

7. **Vantage Point**
   - Is this ground-level, an elevated viewpoint, aerial (drone/plane window), or satellite/overhead imagery?
   - If aerial or satellite: describe field patterns, road grid layout, building density, and coastline/river shape — these are the primary clues at that altitude, not architectural detail.
   - If the image contains multiple distinct, separately-identifiable landmarks, name the most prominent/central one as your primary answer and list the others in `nearby_landmarks`.

**STEP 2: METADATA CLUES** (if provided):
$exif

**STEP 3: BRAINSTORM BEFORE COMMITTING**
Before picking a final answer, silently consider at least 3 plausible locations consistent with the evidence, ranked by how well they fit. Do not skip straight to the first guess that comes to mind — actively consider what would have to be true for a second or third candidate to be correct, and note why you ruled them out (or didn't). Report your top 3 in `candidates`, even if two are very close in likelihood — closeness between candidates is itself useful signal, not something to hide by inflating confidence in one.

**STEP 4: NARROW DOWN** – combine all clues to deduce:
- Continent → Country → Region → City → Specific landmark (if any)

**STEP 5: CONFIDENCE ASSESSMENT**
- **High (80-100%):** Clear, identifiable landmark with exact coordinates, and no close competing candidate.
- **Medium (50-79%):** Strong regional clues, but no precise landmark, or a competing candidate is plausible.
- **Low (below 50%):** Vague, many possibilities, or the image is too generic (blank wall, close-up object, sky only) to localize.
- If you are completely unsure, set confidence **5-10%** and explain specifically what information is missing that would let you narrow it down.

**OUTPUT:** Return ONLY valid JSON with the following structure. All fields are required; use `null` (not the string "null") for unknown numeric fields, and `"Unknown"` for unknown text fields.

```json
{
    "landmark_name": "Name of the specific place or 'Unknown Location'",
    "city": "City name or 'Unknown'",
    "country": "Country name or 'Unknown'",
    "region": "State/Province/Region or 'Unknown'",
    "latitude": decimal or null,
    "longitude": decimal or null,
    "confidence": integer (0-100),
    "reasoning": "Step-by-step reasoning with visual evidence (3-5 sentences)",
    "negative_evidence": "What you considered and ruled out, and why (1-2 sentences). E.g. 'Not Mediterranean Europe despite the tiled roofs, because the script on the signage is Cyrillic.'",
    "vantage_point": "ground|elevated|aerial|satellite|indoor",
    "visual_clues": ["clue1", "clue2", "clue3", "clue4", "clue5"],
    "tags": ["tag1", "tag2", "tag3", "tag4", "tag5"],
    "description": "Brief description of the scene",
    "type": "landmark|city|natural|architectural|historical|street|indoor",
    "nearby_landmarks": ["other landmark visible or nearby, if any"],
    "wikipedia_url": "Wikipedia URL for the identified place, or null if not confident enough to link one",
    "nearest_airport": "Name/IATA code of the nearest major airport, or null if unknown",
    "candidates": [
        {
            "location": "Short name of this candidate location",
            "city": "City or 'Unknown'",
            "country": "Country or 'Unknown'",
            "latitude": decimal or null,
            "longitude": decimal or null,
            "probability": integer (0-100),
            "reasoning": "Why this candidate fits (1-2 sentences)"
        }
    ]
}
```

The first entry in `candidates` should match your primary `landmark_name`/`city`/`country`/`latitude`/`longitude` answer above. Include 2-3 candidates total when there is genuine ambiguity; if you are highly confident, you may include just 1.

**CRITICAL RULES:**
- If you see readable text (e.g., shop signs), include the exact words in `reasoning`.
- If you see a flag, describe it.
- If you are unsure, set confidence to a low number and explain what is missing.
- **NEVER** return an empty or null reasoning. Always explain your thought process.
- **ALWAYS** provide coordinates if you have a strong guess (even if approximate) — for a generic guess, use the nearest city center coordinates rather than leaving both null.
- For images too generic to localize (blank wall, close-up object, sky only), say so plainly in `reasoning` and `negative_evidence` rather than inventing a specific-sounding but baseless answer.

Now analyze the image and return ONLY the JSON.
PROMPT;
    }

    /**
     * Format EXIF for prompt
     */
    private function formatExifForPrompt($metadata)
    {
        if (empty($metadata)) {
            return "No EXIF data available.";
        }

        $lines = [];
        if (isset($metadata['gps']['latitude'], $metadata['gps']['longitude'])) {
            $lines[] = "GPS Coordinates (from camera): {$metadata['gps']['latitude']}, {$metadata['gps']['longitude']}";
        }
        if (isset($metadata['camera']['make'])) {
            $model = $metadata['camera']['model'] ?? '';
            $lines[] = "Camera: {$metadata['camera']['make']} {$model}";
        }
        if (isset($metadata['datetime'])) {
            $lines[] = "DateTime: {$metadata['datetime']}";
        }
        if (isset($metadata['settings']['iso'])) {
            $lines[] = "ISO: {$metadata['settings']['iso']}";
        }
        return empty($lines) ? "No EXIF data available." : implode("\n", $lines);
    }

    /**
     * ULTIMATE ENRICHMENT
     */
    private function ultimateEnrichment($data)
    {
        $data = $this->databaseCrossReference($data);
        $data = $this->multiSourceVerification($data);
        $data = $this->calculateUltimateConfidence($data);
        $data = $this->calibrateConfidenceWithCandidates($data);
        $data = $this->addRichMetadata($data);
        $data = $this->humanizeLabels($data);

        return $data;
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
        if (!is_array($candidates) || count($candidates) < 2) {
            return $data;
        }

        $probs = array_values(array_filter(array_map(
            fn($c) => is_numeric($c['probability'] ?? null) ? (int) $c['probability'] : null,
            $candidates
        ), fn($p) => $p !== null));

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
                && Schema::hasTable((new Landmark())->getTable());
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

        if (!$this->landmarkModelAvailable()) {
            return $data;
        }

        try {
            $query = Landmark::query();

            if (!empty($name)) {
                $query->where('name', 'LIKE', "%{$name}%");
            }
            if (!empty($city)) {
                $query->orWhere('city', 'LIKE', "%{$city}%");
            }
            if (!empty($country)) {
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
                $data['reasoning'] = ($data['reasoning'] ?? '') . " Confirmed by database match: {$landmark->name}.";
                $data['confidence'] = max($data['confidence'] ?? 50, 85);
            }
        } catch (\Exception $e) {
            Log::warning('Landmark DB search failed: ' . $e->getMessage());
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
                Log::warning('Landmark coordinate lookup failed: ' . $e->getMessage());
            }
        }

        if (empty($coords)) {
            return $data;
        }

        $totalWeight = array_sum(array_column($coords, 'weight'));
        $avgLat = array_sum(array_map(fn($c) => $c['lat'] * $c['weight'], $coords)) / $totalWeight;
        $avgLng = array_sum(array_map(fn($c) => $c['lng'] * $c['weight'], $coords)) / $totalWeight;

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

        if (!empty($data['city']) && !empty($data['country']) && $data['city'] !== 'Unknown' && $data['country'] !== 'Unknown') {
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
        if (isset($data['latitude'], $data['longitude']) && !is_null($data['latitude']) && !is_null($data['longitude'])) {
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
                if (!array_key_exists($key, $data)) {
                    $data[$key] = $defaultValue;
                }
                continue;
            }
            if (!array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                $data[$key] = $defaultValue;
            }
        }
        return $data;
    }

    // ========== HELPER METHODS ==========

    /**
     * Downscale/re-encode large images before sending to Gemini.
     * Vision models tile large images internally, so a 4000px photo
     * costs meaningfully more tokens than a 1536px one with no real
     * gain in recognizable detail for OSINT-style clues (signage,
     * architecture, vegetation). Skips silently if GD isn't installed
     * or the image is already small enough — never blocks the request.
     */
    private function compressImageIfNeeded(string $imageData): string
    {
        if (!config('services.gemini.compress_images', true)) {
            return $imageData;
        }

        if (!extension_loaded('gd')) {
            return $imageData;
        }

        $maxDimension = (int) config('services.gemini.max_image_dimension', 1536);
        $sizeThresholdBytes = (int) config('services.gemini.compress_threshold_bytes', 1_500_000);

        $originalSize = strlen($imageData);
        if ($originalSize <= $sizeThresholdBytes) {
            return $imageData;
        }

        try {
            $image = @imagecreatefromstring($imageData);
            if ($image === false) {
                return $imageData;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= $maxDimension && $height <= $maxDimension) {
                imagedestroy($image);
                return $imageData;
            }

            $scale = $maxDimension / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            ob_start();
            imagejpeg($resized, null, 82);
            $compressed = ob_get_clean();

            imagedestroy($image);
            imagedestroy($resized);

            if (!$compressed) {
                return $imageData;
            }

            $compressedSize = strlen($compressed);
            Log::info('Gemini image compressed', [
                'original_kb' => round($originalSize / 1024),
                'compressed_kb' => round($compressedSize / 1024),
                'reduction_pct' => $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100) : 0,
                'dimensions' => "{$width}x{$height} -> {$newWidth}x{$newHeight}",
            ]);

            return $compressed;
        } catch (\Exception $e) {
            Log::warning('Image compression failed, sending original: ' . $e->getMessage());
            return $imageData;
        }
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
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return 'Unknown';
        }
        $offset = (int) round($lng / 15);
        $offset = max(-12, min(14, $offset));
        return $offset >= 0 ? "UTC+{$offset} (est.)" : "UTC{$offset} (est.)";
    }

    private function getConfidenceLevel($confidence)
    {
        if ($confidence >= 95) return 'Verified - Very High';
        if ($confidence >= 80) return 'Very High';
        if ($confidence >= 65) return 'High';
        if ($confidence >= 50) return 'Medium';
        if ($confidence >= 30) return 'Low';
        return 'Very Low';
    }

    private function cleanText($text)
    {
        if (!$text) return '';
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        return trim($text);
    }

    private function cleanArray($data)
    {
        if (is_string($data)) return $this->cleanText($data);
        if (is_array($data)) {
            $cleaned = [];
            foreach ($data as $k => $v) {
                $cleaned[$k] = $this->cleanArray($v);
            }
            return $cleaned;
        }
        return $data;
    }

    /**
     * Parse the model's response into JSON. Tries a direct decode first
     * (fast path, avoids the greedy-regex edge case), then falls back to
     * extracting a fenced/loose JSON block if the model added extra text.
     */
    private function parseAIResponse($text)
    {
        $direct = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($direct)) {
            return $direct;
        }

        preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/', $text, $matches);
        $json = $matches[1] ?? $text;
        $json = trim($json);

        preg_match('/\{[\s\S]*\}/', $json, $jsonMatches);
        $json = $jsonMatches[0] ?? $json;

        // Strip trailing commas the model sometimes leaves before } or ]
        $json = preg_replace('/,\s*}/', '}', $json);
        $json = preg_replace('/,\s*\]/', ']', $json);

        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            Log::error('JSON parse error: ' . $e->getMessage() . ' | raw: ' . substr($text, 0, 500));
            return null;
        }
    }

    private function getFallbackResponse($msg)
    {
        return [
            'landmark_name' => 'Unknown Location',
            'city' => 'Unknown',
            'country' => 'Unknown',
            'region' => 'Unknown',
            'latitude' => null,
            'longitude' => null,
            'confidence' => 0,
            'reasoning' => $msg,
            'visual_clues' => [],
            'tags' => ['Unknown'],
            'description' => 'Unable to determine location.',
            'type' => 'unknown',
            'continent' => 'Unknown',
            'timezone' => 'Unknown',
            'confidence_level' => 'None',
            'negative_evidence' => 'Not specified.',
            'vantage_point' => 'ground',
            'nearby_landmarks' => [],
            'wikipedia_url' => null,
            'nearest_airport' => null,
            'candidates' => [],
            'candidate_gap' => null,
        ];
    }
}