<?php

namespace App\Services\Gemini;

/**
 * Turns technical Gemini failures into a small, fixed set of generic,
 * user-safe messages and stage labels. No exception text, model name or
 * provider detail ever reaches the user through these fields.
 */
final class GeminiFailureClassifier
{
    public const DEFAULT_USER_MESSAGE = 'Analysis could not be completed. Please retry.';

    public const MESSAGES = [
        'quota' => 'The AI service is busy. Please try again in a moment.',
        'overload' => 'The AI service is temporarily unavailable. Please retry shortly.',
        'timeout' => 'The AI service did not respond. Please retry.',
        'bad_request' => 'The request could not be completed. Please try again.',
        'other' => self::DEFAULT_USER_MESSAGE,
        'circuit_open' => 'The AI service is temporarily unavailable. Please retry shortly.',
    ];

    public const STAGE_LABELS = [
        'quota' => 'Waiting for AI service - quota limit',
        'overload' => 'Waiting for AI service - overloaded',
        'timeout' => 'Waiting for AI service - timed out',
        'bad_request' => 'Waiting for AI service - request rejected',
        'other' => 'Waiting for AI service - error',
        'circuit_open' => 'Waiting for AI service - overloaded',
    ];

    public static function classify(array $result): array
    {
        $error = (string) ($result['error'] ?? '');
        $status = isset($result['http_status']) ? (int) $result['http_status'] : null;

        $category = match (true) {
            $error === 'quota_exceeded' || $status === 429 => 'quota',
            $error === 'service_unavailable' || $status === 503 => 'overload',
            in_array($error, ['timeout', 'time_budget_exhausted', 'request_exception', 'connection_error'], true) => 'timeout',
            $error === 'api_error' && $status !== null && $status >= 400 && $status < 500 => 'bad_request',
            $error === 'circuit_open' => 'circuit_open',
            default => 'other',
        };

        return [
            'category' => $category,
            'user_message' => self::MESSAGES[$category],
            'stage_label' => self::STAGE_LABELS[$category],
            'retry_after' => isset($result['retry_after']) ? (int) $result['retry_after'] : null,
        ];
    }

    public static function isSafeUserMessage(?string $message): bool
    {
        if ($message === null) {
            return false;
        }

        return in_array($message, self::MESSAGES, true);
    }

    public static function categoryFromStageLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        foreach (self::STAGE_LABELS as $category => $candidate) {
            if ($label === $candidate) {
                return $category;
            }
        }

        return null;
    }
}
