<?php

namespace App\Services;

use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiPrompt;
use App\Services\Gemini\GeminiResultEnhancer;

class GeminiService
{
    protected $client;

    protected $prompt;

    protected $enhancer;

    public function __construct(GeminiClient $client, GeminiPrompt $prompt, GeminiResultEnhancer $enhancer)
    {
        $this->client = $client;
        $this->prompt = $prompt;
        $this->enhancer = $enhancer;
    }

    /**
     * MAIN ENTRY POINT — coordinates prompt, analysis and enrichment.
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

        return $this->enhancer->enhance($result);
    }
}
