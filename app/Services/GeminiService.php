<?php

namespace App\Services;

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiPrompt;
use App\Services\Gemini\GeminiResultEnhancer;
use App\Services\Gemini\GeminiResultValidator;
use App\Services\Gemini\SecretSanitizer;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected $client;

    protected $prompt;

    protected $enhancer;

    protected $validator;

    public function __construct(GeminiClient $client, GeminiPrompt $prompt, GeminiResultEnhancer $enhancer, GeminiResultValidator $validator)
    {
        $this->client = $client;
        $this->prompt = $prompt;
        $this->enhancer = $enhancer;
        $this->validator = $validator;
    }

    /**
     * MAIN ENTRY POINT — coordinates prompt, analysis, validation and enrichment.
     */
    public function analyzeGeolocation($imageData, $metadata = [], ?callable $onRetry = null)
    {
        if (empty($imageData)) {
            return $this->client->getFallbackResponse('No image data provided.');
        }

        $prompt = $this->prompt->buildUltimateGeoPrompt($metadata);
        $result = $this->client->analyzeWithFallback($imageData, $prompt, $onRetry);

        if (! is_array($result)) {
            return $this->client->getFallbackResponse('AI analysis failed: unknown error');
        }

        if (isset($result['error'])) {
            $fallback = $this->client->getFallbackResponse('AI analysis failed: '.($result['message'] ?? 'unknown error'));
            // Carry the classified, user-safe fields through so the job can
            // set the right stage label and status returns the right message.
            foreach (['category', 'user_message', 'stage_label', 'retry_after', 'retry_after_seconds'] as $key) {
                if (array_key_exists($key, $result)) {
                    $fallback[$key] = $result[$key];
                }
            }

            return $fallback;
        }

        $errors = $this->validator->validate($result);
        if ($errors) {
            Log::warning('Gemini response failed schema validation', ['errors' => $errors]);

            return $this->client->getFallbackResponse('AI response was not valid for the geolocation schema ('.implode(',', $errors).')');
        }

        $enhanced = $this->enhancer->enhance($result, $metadata);

        return $this->maybeEscalate($enhanced, $imageData, $prompt, $metadata);
    }

    /**
     * Optional one-shot second pass with a bounded timeout. Any failure keeps
     * the first-pass result, explicitly marked low confidence.
     */
    private function maybeEscalate(array $first, $imageData, string $prompt, array $metadata): array
    {
        $model = trim((string) config('services.gemini.escalation_model', ''));
        if ($model === '') {
            return $first;
        }

        $threshold = (int) config('services.gemini.escalate_below', 60);
        $firstConfidence = (int) ($first['confidence'] ?? 100);
        if ($firstConfidence >= $threshold) {
            return $first;
        }

        try {
            $timeout = max(1, (int) config('services.gemini.escalation_timeout', 25));
            $second = $this->client->analyzeWithModel($model, $imageData, $prompt, $timeout);

            if (is_array($second)
                && ! isset($second['error'])
                && ! isset($second['is_error'])
                && $this->validator->validate($second) === []) {
                $improved = $this->enhancer->enhance($second, $metadata);
                if ((int) ($improved['confidence'] ?? 0) > $firstConfidence) {
                    $improved['escalated'] = true;

                    return $improved;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini escalation failed: '.SecretSanitizer::redact($e->getMessage()));
        }

        $first['low_confidence'] = true;
        $first['escalation_failed'] = true;

        return $first;
    }

    /**
     * Plain text chat completion (delegates to the client).
     * Throws \RuntimeException on failure.
     */
    public function chat(string $message): string
    {
        return $this->client->generateText($message);
    }
}
