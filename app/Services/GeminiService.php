<?php

namespace App\Services;

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiPrompt;
use App\Services\Gemini\GeminiResultEnhancer;
use App\Services\Gemini\GeminiResultValidator;
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
    public function analyzeGeolocation($imageData, $metadata = [])
    {
        if (empty($imageData)) {
            return $this->client->getFallbackResponse('No image data provided.');
        }

        $prompt = $this->prompt->buildUltimateGeoPrompt($metadata);
        $result = $this->client->analyzeWithFallback($imageData, $prompt);

        if (! is_array($result) || isset($result['error'])) {
            return $this->client->getFallbackResponse('AI analysis failed: '.($result['message'] ?? 'unknown error'));
        }

        $errors = $this->validator->validate($result);
        if ($errors) {
            Log::warning('Gemini response failed schema validation', ['errors' => $errors]);

            return $this->client->getFallbackResponse('AI response was not valid for the geolocation schema ('.implode(',', $errors).')');
        }

        return $this->enhancer->enhance($result);
    }
}
