<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiPrompt;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The slim prompt/schema is opt-in. The default keeps every field that has a
 * consumer; the slim build drops only fields with no reader.
 */
class SlimSchemaGateTest extends TestCase
{
    private function validBody(): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'landmark_name' => 'Eiffel Tower',
                        'city' => 'Paris',
                        'country' => 'France',
                        'confidence' => 80,
                        'reasoning' => 'Tower',
                        'description' => 'Tower',
                    ]),
                ]]],
            ]],
        ];
    }

    private function sentRequest(array $config = []): array
    {
        Http::fake(['*' => Http::response($this->validBody(), 200)]);
        app(GeminiService::class)->analyzeGeolocation('img', []);

        $request = Http::recorded()[0][0];

        return $request->data();
    }

    public function test_default_keeps_the_full_schema_and_prompt(): void
    {
        config(['services.gemini.slim_schema' => false]);
        $data = $this->sentRequest();
        $props = $data['generationConfig']['responseSchema']['properties'];
        $prompt = $data['contents'][0]['parts'][0]['text'];

        foreach (['region', 'tags', 'type', 'negative_evidence', 'nearby_landmarks', 'wikipedia_url', 'nearest_airport'] as $field) {
            $this->assertArrayHasKey($field, $props, "{$field} must be in the default schema");
            $this->assertStringContainsString('"'.$field.'"', $prompt, "{$field} must be in the default prompt");
        }
        foreach (['city', 'country', 'latitude', 'longitude', 'probability', 'reasoning'] as $field) {
            $this->assertArrayHasKey($field, $props['candidates']['items']['properties']);
        }
        $this->assertStringContainsString('3-5 sentences', $prompt);
    }

    public function test_slim_flag_keeps_consumer_fields_and_drops_the_rest(): void
    {
        config(['services.gemini.slim_schema' => true]);
        $data = $this->sentRequest();
        $props = $data['generationConfig']['responseSchema']['properties'];
        $prompt = $data['contents'][0]['parts'][0]['text'];

        // Consumer-backed fields survive.
        foreach (['region', 'tags', 'type'] as $field) {
            $this->assertArrayHasKey($field, $props, "{$field} has a consumer");
            $this->assertStringContainsString($field, $prompt);
        }
        $candidateProps = $props['candidates']['items']['properties'];
        foreach (['location', 'probability', 'reasoning'] as $field) {
            $this->assertArrayHasKey($field, $candidateProps, "candidate {$field} has a consumer");
        }

        // No-consumer fields are dropped.
        foreach (['negative_evidence', 'nearby_landmarks', 'wikipedia_url', 'nearest_airport'] as $field) {
            $this->assertArrayNotHasKey($field, $props, "{$field} has no consumer");
            $this->assertStringNotContainsString('"'.$field.'"', $prompt);
        }
        foreach (['city', 'country', 'latitude', 'longitude'] as $field) {
            $this->assertArrayNotHasKey($field, $candidateProps, "candidate {$field} has no consumer");
        }
        $this->assertStringContainsString('MAXIMUM 2 sentences', $prompt);
    }

    public function test_prompt_builder_respects_the_flag_without_http(): void
    {
        config(['services.gemini.slim_schema' => false]);
        $full = app(GeminiPrompt::class)->buildUltimateGeoPrompt([]);
        config(['services.gemini.slim_schema' => true]);
        $slim = app(GeminiPrompt::class)->buildUltimateGeoPrompt([]);

        $this->assertStringContainsString('negative_evidence', $full);
        $this->assertStringNotContainsString('negative_evidence', $slim);
        $this->assertStringContainsString('region', $slim);
        $this->assertStringContainsString('tags', $slim);
        $this->assertStringContainsString('probability', $slim);
    }
}
