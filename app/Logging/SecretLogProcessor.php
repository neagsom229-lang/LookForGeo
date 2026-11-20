<?php

namespace App\Logging;

use App\Services\Gemini\SecretSanitizer;
use Monolog\LogRecord;

/**
 * Central log sanitizer: every record written through the default log
 * channel has its message AND its context passed through
 * SecretSanitizer, so no key material can reach any log file even if a
 * call site forgets to redact.
 *
 * Registered once in AppServiceProvider::boot() (Laravel resolves the
 * channel lazily; LogManager itself only auto-pushes its ContextProcessor).
 */
class SecretLogProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        // LogRecord properties are readonly (Monolog 3) -> build a new one.
        $message = SecretSanitizer::redact($record->message);
        $context = SecretSanitizer::redactArray($record->context);

        if ($message === $record->message && $context === $record->context) {
            return $record;
        }

        return $record->with(message: $message, context: $context);
    }
}
