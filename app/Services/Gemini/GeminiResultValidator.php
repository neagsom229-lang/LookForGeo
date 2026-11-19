<?php

namespace App\Services\Gemini;

class GeminiResultValidator
{
    /**
     * STRICT validation of the geolocation analysis schema.
     * Returns a list of validation errors (empty when the result is valid).
     *
     * Enforced alongside the API-level responseSchema/responseMimeType:
     * anything the API lets through (e.g. out-of-range confidence) is
     * rejected here instead of being silently cleaned up and trusted.
     */
    public function validate(array $data): array
    {
        $errors = [];

        // Required non-empty strings (the prompt requires these; the schema
        // marks them required). "Unknown"-style sentinel values are allowed —
        // only truly missing/empty/wrong-typed values are rejected.
        foreach (['landmark_name', 'city', 'country', 'reasoning', 'description'] as $key) {
            if (! array_key_exists($key, $data) || ! is_string($data[$key]) || trim($data[$key]) === '') {
                $errors[] = $key;
            }
        }

        // confidence: required numeric, 0-100
        if (! array_key_exists('confidence', $data) || ! is_numeric($data['confidence'])) {
            $errors[] = 'confidence(type)';
        } elseif ((int) $data['confidence'] < 0 || (int) $data['confidence'] > 100) {
            $errors[] = 'confidence(range)';
        }

        // latitude/longitude: optional, but must be numeric and in range when present
        foreach (['latitude' => 90, 'longitude' => 180] as $key => $max) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($data[$key] === null) {
                continue;
            }
            if (! is_numeric($data[$key])) {
                $errors[] = $key.'(type)';

                continue;
            }
            if (abs((float) $data[$key]) > $max) {
                $errors[] = $key.'(range)';
            }
        }

        // vantage_point enum (matches both the prompt and responseSchema)
        if (isset($data['vantage_point'])
            && ! in_array($data['vantage_point'], ['ground', 'elevated', 'aerial', 'satellite', 'indoor'], true)) {
            $errors[] = 'vantage_point(enum)';
        }

        // typed containers when present
        foreach (['visual_clues', 'tags', 'nearby_landmarks'] as $key) {
            if (isset($data[$key]) && ! is_array($data[$key])) {
                $errors[] = $key.'(type)';
            }
        }

        // candidates: optional, but must be an array of objects with location + probability
        if (isset($data['candidates'])) {
            if (! is_array($data['candidates'])) {
                $errors[] = 'candidates(type)';
            } else {
                foreach ($data['candidates'] as $i => $candidate) {
                    if (! is_array($candidate)) {
                        $errors[] = "candidates[$i](type)";

                        continue;
                    }
                    if (! isset($candidate['location']) || ! is_string($candidate['location'])) {
                        $errors[] = "candidates[$i].location";
                    }
                    if (isset($candidate['probability']) && ! is_numeric($candidate['probability'])) {
                        $errors[] = "candidates[$i].probability";
                    }
                }
            }
        }

        return $errors;
    }

    public function isValid(array $data): bool
    {
        return $this->validate($data) === [];
    }
}
