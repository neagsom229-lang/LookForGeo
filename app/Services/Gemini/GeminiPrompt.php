<?php

namespace App\Services\Gemini;

class GeminiPrompt
{
    /**
     * Concise prompt: output tokens dominated latency in live runs, so the
     * reasoning is capped at two sentences and redundant fields are gone.
     */
    public function buildUltimateGeoPrompt($metadata)
    {
        $exif = $this->formatExifForPrompt($metadata);

        return <<<PROMPT
You are an OSINT geolocation analyst. Identify where this photo was taken from visible evidence.

Return ONLY valid JSON with exactly these keys:
- landmark_name: specific place or "Unknown"
- city, country: text or "Unknown"
- latitude, longitude: numbers or null
- confidence: integer 0-100
- reasoning: MAXIMUM 2 sentences naming the strongest visual evidence
- description: 1 sentence describing the scene
- vantage_point: ground|elevated|aerial|satellite|indoor
- visual_clues: array of at most 5 short strings
- candidates: array of up to 3 objects {location, probability, reasoning (1 sentence)}

Use null for unknown coordinates and "Unknown" for unknown text. Do not repeat the prompt or add commentary.

Metadata (may be empty): {$exif}
PROMPT;
    }

    private function formatExifForPrompt($metadata)
    {
        if (empty($metadata)) {
            return 'No EXIF data available.';
        }

        $lines = [];
        if (isset($metadata['gps']['latitude'], $metadata['gps']['longitude'])) {
            $lines[] = "GPS Coordinates (from camera): {$metadata['gps']['latitude']}, {$metadata['gps']['longitude']}";
        }
        if (isset($metadata['camera']['make'])) {
            $lines[] = 'Camera: '.$metadata['camera']['make'].' '.($metadata['camera']['model'] ?? '');
        }
        if (isset($metadata['datetime'])) {
            $lines[] = 'DateTime: '.$metadata['datetime'];
        }
        if (isset($metadata['settings']['iso'])) {
            $lines[] = 'ISO: '.$metadata['settings']['iso'];
        }

        return empty($lines) ? 'No EXIF data available.' : implode("\n", $lines);
    }
}
