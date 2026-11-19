<?php

namespace App\Services\Gemini;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiClient
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
        // PHP's max_execution_time or a reverse-proxy's read timeout,
        // meaning the client gets a 504 well before this method returns
        // anything, including the graceful fallback response. If this
        // analysis genuinely needs more than ~45s of retry budget in your
        // environment, that's a sign it belongs in a queued job rather
        // than a synchronous request.
        $this->maxTotalTimeSeconds = config('services.gemini.max_total_time', 120);

        Log::info('GeminiService initialized', [
            'key_set' => ! empty($this->apiKey),
            'primary' => $this->primaryModel,
            'fallback' => $this->fallbackModel,
            'timeout' => $this->requestTimeout,
            'retries' => $this->maxRetries,
            'max_total_time' => $this->maxTotalTimeSeconds,
        ]);
    }

    /**
     * MAIN ENTRY POINT — PRIMARY + FALLBACK MODEL (with retries, sharing one time budget)
     */
    public function analyzeWithFallback($imageData, $prompt)
    {
        $deadline = microtime(true) + $this->maxTotalTimeSeconds;

        $result = $this->sendGeminiRequestWithRetry($this->primaryModel, $imageData, $prompt, $deadline);
        if (! isset($result['error'])) {
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
    private function sendGeminiRequestWithRetry($model, $imageData, $prompt, $deadline)
    {
        $retries = $this->maxRetries;
        $backoff = 1000000; // microseconds

        for ($attempt = 1; $attempt <= $retries + 1; $attempt++) {
            $remaining = $deadline - microtime(true);

            // No time left at all — don't even try, just report the budget
            // as exhausted so the caller can decide (skip fallback, etc.)
            if ($remaining <= 0) {
                Log::warning("Gemini time budget exhausted before attempt $attempt for {$model}");

                return ['error' => 'time_budget_exhausted', 'message' => 'Ran out of time budget before request could be made'];
            }

            // Cap this request's own timeout to whatever time is actually
            // left, so a single slow attempt can never blow past the shared
            // deadline (and, transitively, can never approach the job's
            // own 180s queue timeout).
            $requestTimeout = (int) max(1, min($this->requestTimeout, floor($remaining)));

            try {
                $result = $this->sendSingleGeminiRequest($model, $imageData, $prompt, $requestTimeout);

                // quota_exceeded is NOT retryable — retrying just burns the budget
                $retryableErrors = ['timeout', 'connection_error', 'service_unavailable', 'server_error'];
                if (isset($result['error']) && in_array($result['error'], $retryableErrors)) {
                    $timeLeftAfterThisAttempt = $deadline - microtime(true);

                    if ($attempt <= $retries && $timeLeftAfterThisAttempt > ($backoff / 1000000)) {
                        Log::warning("Gemini request attempt $attempt failed, retrying in ".($backoff / 1000000).'s', [
                            'error' => $result['message'],
                        ]);
                        usleep($backoff);
                        $backoff *= 2;

                        continue;
                    }

                    return $result; // no retries left, or not enough time budget for another
                }

                return $result;

            } catch (\Exception $e) {
                $timeLeftAfterThisAttempt = $deadline - microtime(true);

                if ($attempt <= $retries && $timeLeftAfterThisAttempt > ($backoff / 1000000)) {
                    Log::warning("Gemini request attempt $attempt threw exception, retrying in ".($backoff / 1000000).'s', [
                        'exception' => $e->getMessage(),
                    ]);
                    usleep($backoff);
                    $backoff *= 2;

                    continue;
                }
                Log::error('Gemini request failed after all retries: '.$e->getMessage());

                return ['error' => 'request_exception', 'message' => $e->getMessage()];
            }
        }

        return ['error' => 'unknown', 'message' => 'Request failed after all retries'];
    }

    /**
     * SEND A SINGLE REQUEST TO GEMINI (no retry logic — that lives in the caller)
     */
    private function sendSingleGeminiRequest($model, $imageData, $prompt, ?int $timeoutSeconds = null)
    {
        if (empty($this->apiKey)) {
            return ['error' => 'api_key_missing', 'message' => 'Gemini API key not configured'];
        }

        $timeoutSeconds = $timeoutSeconds ?? $this->requestTimeout;

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$this->apiKey}";

        $compressedImage = $this->compressImageIfNeeded($imageData);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => 'image/jpeg',
                                'data' => base64_encode($compressedImage),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,       // ignored by gemini-3.x, kept for 2.x fallback
                'maxOutputTokens' => 10000,
                'topP' => 0.95,             // ignored by gemini-3.x, kept for 2.x fallback
                'topK' => 40,               // ignored by gemini-3.x, kept for 2.x fallback
                // gemini-3.x: 'low' | 'high' — 'low' for speed, 'high' for accuracy
                // Structured output: enforced JSON + schema so the response
                // is schema-conformant. Strict validation happens after
                // decoding (see GeminiResultValidator).
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'landmark_name' => ['type' => 'STRING'],
                        'city' => ['type' => 'STRING'],
                        'country' => ['type' => 'STRING'],
                        'region' => ['type' => 'STRING'],
                        'latitude' => ['type' => 'NUMBER', 'nullable' => true],
                        'longitude' => ['type' => 'NUMBER', 'nullable' => true],
                        'confidence' => ['type' => 'INTEGER'],
                        'reasoning' => ['type' => 'STRING'],
                        'negative_evidence' => ['type' => 'STRING'],
                        'vantage_point' => ['type' => 'STRING', 'enum' => ['ground', 'elevated', 'aerial', 'satellite', 'indoor']],
                        'visual_clues' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'description' => ['type' => 'STRING'],
                        'type' => ['type' => 'STRING'],
                        'nearby_landmarks' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                        'wikipedia_url' => ['type' => 'STRING', 'nullable' => true],
                        'nearest_airport' => ['type' => 'STRING', 'nullable' => true],
                        'candidates' => [
                            'type' => 'ARRAY',
                            'items' => [
                                'type' => 'OBJECT',
                                'properties' => [
                                    'location' => ['type' => 'STRING'],
                                    'city' => ['type' => 'STRING'],
                                    'country' => ['type' => 'STRING'],
                                    'latitude' => ['type' => 'NUMBER', 'nullable' => true],
                                    'longitude' => ['type' => 'NUMBER', 'nullable' => true],
                                    'probability' => ['type' => 'INTEGER'],
                                    'reasoning' => ['type' => 'STRING'],
                                ],
                                'required' => ['location', 'probability', 'reasoning'],
                            ],
                        ],
                    ],
                    'required' => ['landmark_name', 'city', 'country', 'confidence', 'reasoning', 'description'],
                ],
            ],
        ];

        try {
            // connectTimeout added explicitly: without it, a hung TCP
            // handshake could still stall past the intended budget on some
            // network conditions even though the read timeout is capped.
            $response = Http::timeout($timeoutSeconds)->connectTimeout(min(10, $timeoutSeconds))->post($url, $payload);

            if ($response->status() === 429) {
                return ['error' => 'quota_exceeded', 'message' => 'API quota exceeded. Try again later.'];
            }

            if ($response->status() === 503) {
                return ['error' => 'service_unavailable', 'message' => 'Gemini service temporarily unavailable (503)'];
            }

            if ($response->status() >= 500) {
                return ['error' => 'server_error', 'message' => 'Gemini server error: '.$response->status()];
            }

            if (! $response->successful()) {
                Log::error("Gemini API error (status {$response->status()}): ".$response->body());

                return ['error' => 'api_error', 'message' => "HTTP {$response->status()}"];
            }

            $data = $response->json();
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                return ['error' => 'empty_response', 'message' => 'Model returned no text content'];
            }

            $text = $this->cleanText($text);
            $parsed = $this->parseAIResponse($text);
            if (! $parsed) {
                return ['error' => 'parse_error', 'message' => 'Could not parse AI response.'];
            }

            return $this->cleanArray($parsed);

        } catch (ConnectionException $e) {
            return ['error' => 'timeout', 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            return ['error' => 'connection_error', 'message' => $e->getMessage()];
        }
    }

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
        if (! config('services.gemini.compress_images', true)) {
            return $imageData;
        }

        if (! extension_loaded('gd')) {
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
            try {
                imagejpeg($resized, null, 82);
                $compressed = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            imagedestroy($image);
            imagedestroy($resized);

            if (! $compressed) {
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
            Log::warning('Image compression failed, sending original: '.$e->getMessage());

            return $imageData;
        }
    }

    private function cleanText($text)
    {
        if (! $text) {
            return '';
        }
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        return trim($text);
    }

    private function cleanArray($data)
    {
        if (is_string($data)) {
            return $this->cleanText($data);
        }
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
     * Parse the model's response into JSON. Structured output is enforced
     * (responseMimeType=application/json + responseSchema at the API level),
     * so a non-decodable body is a hard failure, not something to salvage
     * with regexes. The old regex-recovery path was deleted: it could
     * fabricate a "valid" result out of a broken response.
     */
    private function parseAIResponse($text)
    {
        $parsed = json_decode($text, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($parsed)) {
            Log::error('Gemini response was not valid JSON: '.substr($text, 0, 500));

            return null;
        }

        return $parsed;
    }

    /**
     * Plain text generation (no image) — used by the /api/gemini-chat endpoint.
     * Throws \RuntimeException on transport or API failure so callers can
     * surface a clean 500 instead of an opaque crash.
     */
    public function generateText(string $prompt): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Gemini API key not configured');
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->primaryModel}:generateContent?key={$this->apiKey}";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.7,
                'maxOutputTokens' => 1024,
            ],
        ];

        $response = Http::timeout(30)->connectTimeout(min(10, 30))->post($url, $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('Gemini request failed: HTTP '.$response->status());
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Gemini returned no text content');
        }

        return trim($text);
    }

    public function getFallbackResponse($msg)
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
            'is_error' => true,           // <-- ADD THIS
            'error_message' => $msg,
        ];
    }
}
