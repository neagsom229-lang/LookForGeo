<?php

namespace App\Services\Gemini;

/**
 * ONE central place that strips secret key material out of any text before
 * it is logged, stored in the database, thrown as an exception or returned
 * in an API response.
 *
 * Patterns covered:
 *  - key=...    URL query strings / form parameters (value removed entirely)
 *  - AIza...    Google API keys
 *  - AQ...      AQ-prefixed keys/tokens
 */
class SecretSanitizer
{
    public const REDACTED = '[REDACTED]';

    /**
     * Redact secret material from a string. Never throws.
     */
    public static function redact(?string $text): string
    {
        if ($text === null || $text === '') {
            return (string) $text;
        }

        // key=<value> in URLs / query strings / form posts. \b keeps
        // "apikey=" / "monkey=" from matching while "x-goog-api-key=" does.
        $text = preg_replace('/\bkey=[^&\s"\'<>]+/', 'key='.self::REDACTED, (string) $text);

        // Google API keys: AIza + 10+ base64url characters.
        $text = preg_replace('/AIza[0-9A-Za-z_\-]{10,}/', self::REDACTED, (string) $text);

        // AQ-prefixed keys/tokens.
        $text = preg_replace('/AQ[0-9A-Za-z_\-\.]{6,}/', self::REDACTED, (string) $text);

        return (string) $text;
    }

    /**
     * Recursively redact every string inside an array (log context, JSON
     * payloads, exception context, ...).
     */
    public static function redactArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = self::redact($value);
            } elseif (is_array($value)) {
                $data[$key] = self::redactArray($value);
            }
        }

        return $data;
    }
}
