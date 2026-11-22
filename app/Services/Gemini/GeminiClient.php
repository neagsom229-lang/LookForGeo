<?php

namespace App\Services\Gemini;

use App\Models\GeoAnalysis;
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

    protected $minAttemptSeconds;

    protected $retryBackoffMs;

    protected $thinkingLevel;

    /** @var array<string,string> */
    protected array $thinkingOverrides = [];

    /** @var array<string,int> */
    protected array $timeoutOverrides = [];

    protected $baseUrl;

    /** Monotonic start of the in-flight Gemini call, for elapsed_ms. */
    private ?int $lastAttemptNs = null;

    public function __construct(private GeminiCircuitBreaker $circuit)
    {
        $this->apiKey = config('services.gemini.key');
        $this->primaryModel = config('services.gemini.model', 'gemini-3.5-flash-lite');
        // Fallback should be a *stable, different-generation* model so a
        // primary outage or deprecation doesn't take down both. As of
        // Aug 2026, gemini-3.5-flash is the prior-generation stable Flash.
        $this->fallbackModel = config('services.gemini.fallback_model', 'gemini-3.1-flash-lite');
        $this->requestTimeout = max(1, (int) config('services.gemini.timeout', 50));
        // Exactly one retry on the primary model. More retries only stack
        // latency and push the user past the two-minute failure target.
        $this->maxRetries = min(1, max(0, (int) config('services.gemini.retries', 1)));
        $this->maxTotalTimeSeconds = max(1, (int) config('services.gemini.max_total_time', 110));
        $this->minAttemptSeconds = max(1, (int) config('services.gemini.min_attempt_seconds', 15));
        $this->retryBackoffMs = max(0, (int) config('services.gemini.retry_backoff_ms', 1000));
        // Keep the configured casing (default lowercase "low"). An empty
        // value omits thinkingConfig entirely.
        $this->thinkingLevel = trim((string) config('services.gemini.thinking_level', 'low'));

        foreach (explode(',', (string) config('services.gemini.timeout_overrides', '')) as $pair) {
            if (str_contains($pair, '=')) {
                [$modelName, $seconds] = explode('=', trim($pair), 2);
                $this->timeoutOverrides[trim($modelName)] = max(1, (int) trim($seconds));
            }
        }

        foreach (explode(',', (string) config('services.gemini.thinking_overrides', '')) as $pair) {
            if (str_contains($pair, '=')) {
                [$modelName, $level] = explode('=', trim($pair), 2);
                $this->thinkingOverrides[trim($modelName)] = trim($level);
            }
        }
        // Base URL for the Gemini REST API. Local/testing may override it
        // (e.g. fake server in smoke tests); everywhere else only https.
        $this->baseUrl = self::resolveBaseUrl(
            config('services.gemini.base_url'),
            app()->environment()
        );
        // Deliberately no INFO log here: this object is resolved from a
        // queue job, not from every status poll. Logging on construction
        // made a per-poll log line that told us nothing about the job.
    }

    /**
     * Resolve the Gemini API base URL with environment rules:
     *  - local / testing: GEMINI_BASE_URL is honored (smoke tests use a fake
     *    HTTP server; cleartext is fine on loopback);
     *  - anything else: only an https override is accepted -- otherwise fall
     *    back to the real endpoint, and refuse to run when an unsafe
     *    (non-https) override is configured.
     */
    public static function resolveBaseUrl(?string $override, string $environment = ''): string
    {
        $default = 'https://generativelanguage.googleapis.com/v1beta';

        if (in_array($environment, ['local', 'testing'], true)) {
            return rtrim((string) ($override ?: $default), '/');
        }

        if ($override !== null && trim($override) !== '' && ! str_starts_with(strtolower(trim($override)), 'https://')) {
            throw new \RuntimeException(
                'GEMINI_BASE_URL must be https outside local/testing environments; '
                .'refusing to send the API key over an insecure connection.'
            );
        }

        return rtrim((string) ($override ?: $default), '/');
    }

    /**
     * MAIN ENTRY POINT — PRIMARY + FALLBACK MODEL (one retry, shared budget)
     *
     * Order is fixed: primary, one retry on the primary, then one fallback
     * model. No model is called with less than the configured minimum
     * attempt window left, so a 30s request can never start at second 89
     * of a 90s budget and overshoot the user-visible failure target.
     */
    private function hedgeEnabled(): bool
    {
        $after = (float) config('services.gemini.hedge_after', 6);

        return $after > 0
            && $this->primaryModel !== $this->fallbackModel
            && ! $this->dailyCapNearLimit();
    }

    private function dailyCapNearLimit(): bool
    {
        $limit = (int) config('services.gemini.daily_limit_global', 0);
        if ($limit <= 0) {
            return false;
        }
        $used = (int) GeoAnalysis::whereDate('created_at', today())->count();

        return ($used / $limit) > 0.8;
    }

    /**
     * Hedge: give the primary only the hedge window, then immediately start
     * the fallback. The first valid response wins; a primary that merely ran
     * out of the hedge window is not recorded as a breaker failure.
     *
     * @return array{result: array, winner: string, failures: array<int,string>}|null
     */
    private function sendPrimaryTimeoutThenFallback(string $imageData, string $prompt): ?array
    {
        if (! $this->hedgeEnabled()) {
            return null;
        }

        $after = (float) config('services.gemini.hedge_after', 6);
        $started = microtime(true);
        $primaryResult = $this->sendSingleGeminiRequest(
            $this->primaryModel, $imageData, $prompt, max(1, (int) ceil($after))
        );

        if (! isset($primaryResult['error'])) {
            return ['result' => $primaryResult, 'winner' => $this->primaryModel, 'failures' => []];
        }

        $abandonedMs = (int) round((microtime(true) - $started) * 1000);

        // Primary did not answer in the hedge window: its elapsed time is recorded and the fallback starts now.
        $fallbackResult = $this->sendSingleGeminiRequest(
            $this->fallbackModel, $imageData, $prompt, $this->timeoutForModel($this->fallbackModel)
        );

        $primaryClass = $this->withClassification($primaryResult);
        $fallbackClass = isset($fallbackResult['error']) ? $this->withClassification($fallbackResult) : $fallbackResult;

        $winner = isset($fallbackResult['error']) ? 'none' : $this->fallbackModel;
        $result = isset($fallbackResult['error']) ? $fallbackClass : $fallbackResult;
        $failures = [];

        if (isset($fallbackResult['error'])) {
            // Both models genuinely failed.
            $failures = [$this->primaryModel, $this->fallbackModel];
            $result = array_merge($primaryClass, [
                'error' => $fallbackClass['error'],
                'message' => $fallbackClass['message'],
                'category' => $fallbackClass['category'],
                'user_message' => $fallbackClass['user_message'],
                'stage_label' => $fallbackClass['stage_label'],
            ]);
        }

        Log::info('Gemini primary timeout + fallback', [
            'hedged' => true,
            'winner' => $winner,
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        $result['hedged'] = true;
        $result['hedge_winner'] = $winner;
        $result['abandoned_primary'] = $this->primaryModel;
        $result['primary_abandoned_ms'] = $abandonedMs;

        return ['result' => $result, 'winner' => $winner, 'failures' => $failures];
    }

    public function analyzeWithFallback($imageData, $prompt, ?callable $onRetry = null)
    {
        // Hedged first pass: start the fallback only if the primary is slow.
        $hedged = $this->sendPrimaryTimeoutThenFallback($imageData, $prompt);
        if ($hedged !== null) {
            $result = $hedged['result'];
            $classified = isset($result['error']) ? $this->withClassification($result) : $result;

            if (! isset($result['error'])) {
                $this->circuit->recordSuccess($hedged['winner']);
                $result['winner'] = $hedged['winner'];

                return $result;
            }

            // Only real failures of a model count toward its breaker.
            foreach ($hedged['failures'] as $failedModel) {
                $this->noteCircuitFailure($failedModel, $classified);
            }

            return $classified;
        }

        $deadline = microtime(true) + $this->maxTotalTimeSeconds;
        $models = array_values(array_unique([$this->primaryModel, $this->fallbackModel]));
        $last = ['error' => 'unknown', 'message' => 'No model attempted'];
        $isPrimary = true;

        // Per-model breakers: an open primary is skipped immediately so a
        // hung/overloaded primary cannot delay the fallback.
        foreach ($models as $model) {
            if ($this->circuit->isOpen($model)) {
                Log::warning('Gemini breaker open, skipping model', ['model' => $model]);
                $last = $this->circuitOpenResult($model);
                $isPrimary = false;

                continue;
            }

            if (! $this->hasBudgetForAttempt($deadline)) {
                Log::warning('Time budget exhausted before attempt for '.$model);
                $last = [
                    'error' => 'time_budget_exhausted',
                    'message' => 'Ran out of time budget before request could be made',
                ];

                break;
            }

            $attempts = $isPrimary ? 1 + $this->maxRetries : 1;
            $retryNumber = $isPrimary ? 1 : $this->maxRetries + 1;
            if (! $isPrimary) {
                $this->notifyRetry($onRetry, $model, $retryNumber);
            }

            $result = $this->sendGeminiRequestWithRetry(
                $model, $imageData, $prompt, $deadline, $attempts, $onRetry, $retryNumber
            );

            if (! isset($result['error'])) {
                $this->circuit->recordSuccess($model);
                $result['winner'] = $model;

                return $result;
            }

            $result = $this->withClassification($result);
            if ($result['error'] === 'api_key_missing') {
                return $result;
            }

            $this->noteCircuitFailure($model, $result);
            $last = $result;
            $isPrimary = false;
        }

        return $last;
    }

    private function withClassification(array $result): array
    {
        return array_merge($result, GeminiFailureClassifier::classify($result));
    }

    private function noteCircuitFailure(string $model, array $result): void
    {
        if (in_array($result['category'] ?? null, ['quota', 'overload', 'timeout'], true)) {
            $this->circuit->recordFailure($model, $result['retry_after'] ?? null);
        }
    }

    private function circuitOpenResult(string $model): array
    {
        $classified = GeminiFailureClassifier::classify(['error' => 'circuit_open']);

        return array_merge([
            'error' => 'circuit_open',
            'message' => $classified['user_message'],
            'model' => $model,
            'retry_after_seconds' => $this->circuit->remainingSeconds($model),
        ], $classified);
    }

    /**
     * SEND REQUEST WITH RETRY LOGIC
     *
     * Retries on connection-level failures (timeout, connection reset)
     * AND on 5xx server errors from Gemini, since those are typically
     * transient too. Never retries 429 (quota): a rate limit will not
     * clear in the ~1s backoff window, so retrying only burns budget.
     */
    private function sendGeminiRequestWithRetry(
        $model,
        $imageData,
        $prompt,
        $deadline,
        int $maxAttempts = 1,
        ?callable $onRetry = null,
        int $retryNumber = 1
    ) {
        $backoff = $this->retryBackoffMs * 1000; // milliseconds -> microseconds
        $attempts = max(1, $maxAttempts);

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $remaining = $deadline - microtime(true);

            // Do not start a request that cannot meaningfully finish.
            if ($remaining < $this->minAttemptSeconds) {
                Log::warning("Gemini time budget exhausted before attempt $attempt for {$model}");

                return ['error' => 'time_budget_exhausted', 'message' => 'Ran out of time budget before request could be made'];
            }

            // Cap this request's own timeout to whatever time is actually
            // left, so a single slow attempt can never blow past the shared
            // deadline (and, transitively, can never approach the job's
            // own queue timeout).
            $requestTimeout = (int) max(1, min($this->timeoutForModel($model), floor($remaining)));

            try {
                $result = $this->sendSingleGeminiRequest($model, $imageData, $prompt, $requestTimeout);

                // quota_exceeded is NOT retryable — retrying just burns the budget
                $retryableErrors = ['timeout', 'connection_error', 'service_unavailable', 'server_error'];
                if (isset($result['error']) && in_array($result['error'], $retryableErrors)) {
                    $timeLeftAfterThisAttempt = $deadline - microtime(true);

                    if ($attempt < $attempts && $timeLeftAfterThisAttempt >= ($this->minAttemptSeconds + ($backoff / 1000000))) {
                        $this->notifyRetry($onRetry, $model, $retryNumber);
                        Log::warning("Gemini request attempt $attempt failed, retrying in ".($backoff / 1000000).'s', [
                            'error' => $result['message'],
                        ]);
                        usleep($backoff);

                        continue;
                    }

                    return $result; // no retries left, or not enough time budget for another
                }

                return $result;

            } catch (\Throwable $e) {
                $timeLeftAfterThisAttempt = $deadline - microtime(true);

                if ($attempt < $attempts && $timeLeftAfterThisAttempt >= ($this->minAttemptSeconds + ($backoff / 1000000))) {
                    $this->notifyRetry($onRetry, $model, $retryNumber);
                    Log::warning("Gemini request attempt $attempt threw exception, retrying in ".($backoff / 1000000).'s', [
                        'exception' => SecretSanitizer::redact($e->getMessage()),
                    ]);
                    usleep($backoff);

                    continue;
                }
                Log::error('Gemini request failed after all retries: '.SecretSanitizer::redact($e->getMessage()));

                return ['error' => 'request_exception', 'message' => SecretSanitizer::redact($e->getMessage())];
            }
        }

        return ['error' => 'unknown', 'message' => 'Request failed after all retries'];
    }

    private function hasBudgetForAttempt(float $deadline): bool
    {
        return ($deadline - microtime(true)) >= $this->minAttemptSeconds;
    }

    private function notifyRetry(?callable $onRetry, string $model, int $retryNumber): void
    {
        if (! $onRetry) {
            return;
        }

        try {
            $onRetry($model, $retryNumber);
        } catch (\Throwable $e) {
            Log::warning('Gemini retry callback failed: '.SecretSanitizer::redact($e->getMessage()));
        }
    }

    /**
     * Gemini 3.x rejects the deprecated temperature/topP/topK fields and
     * instead uses thinkingConfig.thinkingLevel. Older (2.x) models still
     * need those fields omitted rather than sent with unknown values, so we
     * only add the thinking config for 3.x models.
     */
    public function timeoutForModel(string $model): int
    {
        if (isset($this->timeoutOverrides[$model])) {
            return $this->timeoutOverrides[$model];
        }

        if (str_contains($model, 'flash-lite')) {
            return 20;
        }

        if (str_contains($model, 'flash')) {
            return max(20, $this->requestTimeout);
        }

        return $this->requestTimeout;
    }

    private function generationConfig(string $model, array $config): array
    {
        $level = $this->thinkingLevelFor($model);
        if ($level !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $level];
        }

        return $config;
    }

    /**
     * Per-family thinking level, with exact-model overrides taking priority.
     * Gemini 2.x has no thinkingConfig; omit it there.
     */
    private function thinkingLevelFor(string $model): ?string
    {
        if (array_key_exists($model, $this->thinkingOverrides)) {
            $override = $this->thinkingOverrides[$model];

            return $override === '' ? null : $override;
        }

        if (! str_starts_with($model, 'gemini-3')) {
            return null;
        }

        if (str_contains($model, 'flash-lite')) {
            // "minimal" is opt-in only via GEMINI_THINKING_OVERRIDES after a
            // live 200 has been recorded in docs/DEPLOY_NOTES.md.
            return 'low';
        }

        return $this->thinkingLevel === '' ? null : $this->thinkingLevel;
    }

    /**
     * One redacted log line per Gemini attempt. Production ships these to
     * stderr as JSON; the latency/smoke scripts print the last lines on a
     * failed run. No key or image bytes are ever logged.
     */
    private function finishAttempt(
        array $result,
        string $model,
        float $started,
        int $imageBytes,
        ?int $status = null,
        ?\Throwable $exception = null
    ): array {
        $elapsedMs = $this->lastAttemptNs !== null
            ? (int) round((hrtime(true) - $this->lastAttemptNs) / 1_000_000)
            : (int) round((microtime(true) - $started) * 1000);
        $result['gemini_ms'] = $elapsedMs;
        $context = [
            'model' => $model,
            'elapsed_ms' => $elapsedMs,
            'thinking_level' => str_starts_with($model, 'gemini-3') ? $this->thinkingLevel : 'n/a',
            'image_bytes' => $imageBytes,
            'prompt_tokens' => (int) ($result['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
            'thought_tokens' => (int) ($result['thought_tokens'] ?? 0),
            'category' => isset($result['error'])
                ? GeminiFailureClassifier::classify($result)['category']
                : 'success',
        ];
        if ($exception !== null) {
            $context['exception_class'] = get_class($exception);
        } else {
            $context['http_status'] = $status;
        }

        Log::info('Gemini attempt', $context);

        return $result;
    }

    private function effectiveThinkingLevel(string $model): string
    {
        return $this->thinkingLevelFor($model) ?? 'n/a';
    }

    /**
     * SEND A SINGLE REQUEST TO GEMINI (no retry logic — that lives in the caller)
     */
    /**
     * Full schema is the default. Slim is opt-in via GEMINI_SLIM_SCHEMA and
     * keeps every field that has a real consumer (region, tags, type and
     * candidate location/probability/reasoning). The remaining fields are
     * backfilled by GeminiResultEnhancer's defaults.
     */
    private function analysisResponseSchema(): array
    {
        $base = [
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
        ];

        if (! (bool) config('services.gemini.slim_schema', false)) {
            return $base;
        }

        // Slim: drop only fields with no consumer. Keep the ones the UI,
        // landmark cross-reference and confidence calculator read.
        foreach (['negative_evidence', 'nearby_landmarks', 'wikipedia_url', 'nearest_airport'] as $key) {
            unset($base['properties'][$key]);
        }
        foreach (['city', 'country', 'latitude', 'longitude'] as $key) {
            unset($base['properties']['candidates']['items']['properties'][$key]);
        }

        return $base;
    }

    private function sendSingleGeminiRequest($model, $imageData, $prompt, ?int $timeoutSeconds = null)
    {
        if (empty($this->apiKey)) {
            return ['error' => 'api_key_missing', 'message' => 'Gemini API key not configured'];
        }

        $timeoutSeconds = $timeoutSeconds ?? $this->requestTimeout;

        // SECURITY: the key travels ONLY in the x-goog-api-key header --
        // never in the URL, so it cannot leak via logs, referrers or
        // exception messages that embed the request URL.
        $url = "{$this->baseUrl}/models/{$model}:generateContent";

        $compressed = $this->compressImageIfNeeded($imageData);
        $compressedImage = $compressed['data'];
        $imageMime = $compressed['mime'];
        $imageBytes = strlen($compressedImage);
        $attemptStarted = microtime(true);
        // hrtime() is monotonic: a wall-clock adjustment cannot corrupt the
        // per-attempt latency we persist and chart in analyses:stats.
        $this->lastAttemptNs = hrtime(true);

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $imageMime,
                                'data' => base64_encode($compressedImage),
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => $this->generationConfig($model, [
                'maxOutputTokens' => 10000,
                // Structured output: enforced JSON + schema so the response
                // is schema-conformant. Strict validation happens after
                // decoding (see GeminiResultValidator).
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->analysisResponseSchema(),
            ]),
        ];

        try {
            // connectTimeout added explicitly: without it, a hung TCP
            // handshake could still stall past the intended budget on some
            // network conditions even though the read timeout is capped.
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout(min(10, $timeoutSeconds))
                ->withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->post($url, $payload);
            $status = $response->status();

            if ($status === 429) {
                $retryAfter = (int) $response->header('Retry-After');

                return $this->finishAttempt(
                    [
                        'error' => 'quota_exceeded',
                        'message' => 'API quota exceeded. Try again later.',
                        'http_status' => $status,
                        'retry_after' => $retryAfter,
                    ],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            if ($status === 503) {
                return $this->finishAttempt(
                    [
                        'error' => 'service_unavailable',
                        'message' => 'Gemini service temporarily unavailable (503)',
                        'http_status' => $status,
                    ],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            if ($status >= 500) {
                return $this->finishAttempt(
                    [
                        'error' => 'server_error',
                        'message' => 'Gemini server error: '.$status,
                        'http_status' => $status,
                    ],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            if (! $response->successful()) {
                Log::error("Gemini API error (status {$status}): ".SecretSanitizer::redact($response->body()));

                return $this->finishAttempt(
                    ['error' => 'api_error', 'message' => "HTTP {$status}", 'http_status' => $status],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            $data = $response->json();
            $usage = is_array($data['usageMetadata'] ?? null) ? $data['usageMetadata'] : [];
            $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                return $this->finishAttempt(
                    ['error' => 'empty_response', 'message' => 'Model returned no text content'],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            $text = $this->cleanText($text);
            $parsed = $this->parseAIResponse($text);
            if (! $parsed) {
                return $this->finishAttempt(
                    ['error' => 'parse_error', 'message' => 'Could not parse AI response.'],
                    $model, $attemptStarted, $imageBytes, $status
                );
            }

            $result = $this->cleanArray($parsed);
            $result['prompt_tokens'] = (int) ($usage['promptTokenCount'] ?? 0);
            $result['output_tokens'] = (int) ($usage['candidatesTokenCount'] ?? 0);
            $result['thought_tokens'] = (int) ($usage['thoughtsTokenCount'] ?? 0);

            return $this->finishAttempt($result, $model, $attemptStarted, $imageBytes, $status);

        } catch (ConnectionException $e) {
            return $this->finishAttempt(
                ['error' => 'timeout', 'message' => SecretSanitizer::redact($e->getMessage())],
                $model, $attemptStarted, $imageBytes, null, $e
            );
        } catch (\Exception $e) {
            return $this->finishAttempt(
                ['error' => 'connection_error', 'message' => SecretSanitizer::redact($e->getMessage())],
                $model, $attemptStarted, $imageBytes, null, $e
            );
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
    /**
     * @return array{data: string, mime: string}
     */
    private function compressImageIfNeeded(string $imageData): array
    {
        $original = ['data' => $imageData, 'mime' => 'image/jpeg'];

        if (! config('services.gemini.compress_images', true) || ! extension_loaded('gd')) {
            return $original;
        }

        $maxDimension = (int) config('services.gemini.max_image_dimension', 1024);
        $sizeThresholdBytes = (int) config('services.gemini.compress_threshold_bytes', 1_000_000);

        $originalSize = strlen($imageData);
        if ($originalSize <= $sizeThresholdBytes) {
            return $original;
        }

        try {
            $image = @imagecreatefromstring($imageData);
            if ($image === false) {
                return $original;
            }

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width <= $maxDimension && $height <= $maxDimension) {
                imagedestroy($image);

                return $original;
            }

            $scale = $maxDimension / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

            // Encoder choice is explicit (config, forced in tests) so the
            // result never depends on whether the runner's GD has JPEG.
            // "auto" prefers JPEG and falls back to PNG when unavailable.
            $encoder = strtolower((string) config('services.gemini.image_encoder', 'auto'));
            $useJpeg = $encoder === 'jpeg' || ($encoder === 'auto' && function_exists('imagejpeg'));
            $usePng = $encoder === 'png' || ($encoder === 'auto' && ! function_exists('imagejpeg'));

            ob_start();
            try {
                if ($useJpeg && function_exists('imagejpeg')) {
                    imagejpeg($resized, null, 82);
                    $mime = 'image/jpeg';
                } elseif ($usePng && function_exists('imagepng')) {
                    imagepng($resized, null, 6);
                    $mime = 'image/png';
                } elseif (function_exists('imagepng')) {
                    // Requested encoder missing: still produce a valid image.
                    imagepng($resized, null, 6);
                    $mime = 'image/png';
                } elseif (function_exists('imagejpeg')) {
                    imagejpeg($resized, null, 82);
                    $mime = 'image/jpeg';
                } else {
                    throw new \RuntimeException('GD has no JPEG or PNG encoder');
                }
                $compressed = ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            imagedestroy($image);
            imagedestroy($resized);

            if (! $compressed) {
                return $original;
            }

            Log::info('Gemini image compressed', [
                'original_kb' => round($originalSize / 1024),
                'compressed_kb' => round(strlen($compressed) / 1024),
                'reduction_pct' => $originalSize > 0 ? round((1 - strlen($compressed) / $originalSize) * 100) : 0,
                'dimensions' => "{$width}x{$height} -> {$newWidth}x{$newHeight}",
                'mime' => $mime,
            ]);

            return ['data' => $compressed, 'mime' => $mime];
        } catch (\Throwable $e) {
            // Never let image preparation fail the analysis.
            Log::warning('Image compression failed, sending original: '.SecretSanitizer::redact($e->getMessage()));

            return $original;
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
    /**
     * Single bounded attempt used by the optional escalation pass.
     */
    public function analyzeWithModel(string $model, $imageData, string $prompt, int $timeout = 25): array
    {
        if ($this->circuit->isOpen($model)) {
            return $this->circuitOpenResult($model);
        }

        return $this->sendSingleGeminiRequest($model, $imageData, $prompt, $timeout);
    }

    public function generateText(string $prompt): string
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Gemini API key not configured');
        }

        // SECURITY: key in header only, never in the URL.
        $url = "{$this->baseUrl}/models/{$this->primaryModel}:generateContent";

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => $this->generationConfig($this->primaryModel, [
                'maxOutputTokens' => 1024,
            ]),
        ];

        try {
            $response = Http::timeout($this->requestTimeout)
                ->connectTimeout(min(10, $this->requestTimeout))
                ->withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->post($url, $payload);
        } catch (\Throwable $e) {
            // Any transport exception may embed the request URL/details --
            // sanitize before it can reach a controller response or a log.
            throw new \RuntimeException(
                SecretSanitizer::redact('Gemini request failed: '.$e->getMessage()),
                0,
                $e
            );
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                SecretSanitizer::redact('Gemini request failed: HTTP '.$response->status())
            );
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($text) || trim($text) === '') {
            throw new \RuntimeException('Gemini returned no text content');
        }

        return trim($text);
    }

    /**
     * Send a minimal text-only request to one model and report only
     * non-secret metadata: model name, HTTP status and elapsed seconds.
     * Used by `php artisan gemini:probe` / `smoke.ps1 -Live` so we can
     * measure each configured model without ever printing the API key.
     *
     * @return array{model: string, status: int, seconds: float, ok: bool, category: string, error?: string, error_message?: string|null, quota_metric?: string|null, quota_id?: string|null, retry_after_seconds?: int}
     */
    /** Prepare real image bytes exactly as the app does before sending. */
    public function prepareImageForModel(string $imageData): array
    {
        return $this->compressImageIfNeeded($imageData);
    }

    public function probeModel(string $model, ?string $thinkingOverride = null, ?string $imageData = null, ?string $imageMime = null): array
    {
        $started = microtime(true);

        if (empty($this->apiKey)) {
            return [
                'model' => $model,
                'status' => 0,
                'seconds' => round(microtime(true) - $started, 2),
                'ok' => false,
                'category' => 'other',
                'error' => 'Gemini API key not configured',
            ];
        }

        $url = "{$this->baseUrl}/models/{$model}:generateContent";
        $png = $imageData ?? $this->tinyProbePng();
        $mime = $imageMime ?? 'image/png';
        $imageBytes = strlen($png);

        $generationConfig = $this->generationConfig($model, [
            'maxOutputTokens' => 32,
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'OBJECT',
                'properties' => ['ok' => ['type' => 'BOOLEAN']],
                'required' => ['ok'],
            ],
        ]);
        if ($thinkingOverride !== null && $thinkingOverride !== '') {
            $generationConfig['thinkingConfig'] = ['thinkingLevel' => $thinkingOverride];
        }

        try {
            $response = Http::timeout($this->requestTimeout)
                ->connectTimeout(min(10, $this->requestTimeout))
                ->withHeaders(['x-goog-api-key' => (string) $this->apiKey])
                ->post($url, [
                    'contents' => [[
                        'parts' => [
                            ['text' => 'Reply with the JSON object {"ok":true}.'],
                            ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($png)]],
                        ],
                    ]],
                    // Same shape as a real analysis request: structured JSON,
                    // a response schema and (for Gemini 3.x) thinking config.
                    'generationConfig' => $generationConfig,
                ]);

            $status = $response->status();
            $errorMessage = null;
            $quotaMetric = null;
            $quotaId = null;

            if (! $response->successful()) {
                $body = $response->json();
                $error = is_array($body) ? ($body['error'] ?? []) : [];
                $errorMessage = SecretSanitizer::redact(substr(
                    (string) ($error['message'] ?? $response->body()), 0, 200
                ));
                foreach ((array) ($error['details'] ?? []) as $detail) {
                    if (! is_array($detail)) {
                        continue;
                    }
                    $quotaMetric = $quotaMetric ?: SecretSanitizer::redact(substr((string) ($detail['quotaMetric'] ?? ''), 0, 200));
                    $quotaId = $quotaId ?: SecretSanitizer::redact(substr((string) ($detail['quotaId'] ?? ''), 0, 200));
                }
            }

            Log::info('Gemini attempt', [
                'model' => $model,
                'http_status' => $status,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'thinking_level' => $thinkingOverride ?: $this->effectiveThinkingLevel($model),
                'image_bytes' => $imageBytes,
                'probe' => true,
            ]);

            return [
                'model' => $model,
                'status' => $status,
                'seconds' => round(microtime(true) - $started, 2),
                'ok' => $response->successful(),
                'thinking_level' => $thinkingOverride ?: $this->effectiveThinkingLevel($model),
                'category' => $response->successful() ? 'ok' : match (true) {
                    $status === 429 => 'quota',
                    $status === 503 => 'overload',
                    $status >= 400 && $status < 500 => 'bad_request',
                    default => 'other',
                },
                'error_message' => $errorMessage,
                'quota_metric' => $quotaMetric,
                'quota_id' => $quotaId,
            ];
        } catch (\Throwable $e) {
            Log::info('Gemini attempt', [
                'model' => $model,
                'exception_class' => get_class($e),
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                'thinking_level' => $thinkingOverride ?: $this->effectiveThinkingLevel($model),
                'image_bytes' => $imageBytes,
                'probe' => true,
            ]);

            return [
                'model' => $model,
                'status' => 0,
                'seconds' => round(microtime(true) - $started, 2),
                'ok' => false,
                'thinking_level' => $thinkingOverride ?: $this->effectiveThinkingLevel($model),
                'category' => $e instanceof ConnectionException ? 'timeout' : 'other',
                'error' => SecretSanitizer::redact($e->getMessage()),
                'error_message' => SecretSanitizer::redact(substr($e->getMessage(), 0, 200)),
                'quota_metric' => null,
                'quota_id' => null,
            ];
        }
    }

    private function tinyProbePng(): string
    {
        if (function_exists('imagecreatetruecolor')) {
            $image = imagecreatetruecolor(2, 2);
            imagefilledrectangle($image, 0, 0, 1, 1, imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagepng($image);
            $bytes = (string) ob_get_clean();

            return $bytes;
        }

        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg==');
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
