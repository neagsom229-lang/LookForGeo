<?php

namespace App\Logging;

use Monolog\LogRecord;

/**
 * Production logs are machine-parsed JSON: strip decorative emoji from
 * messages and string context. This channel is only used in production,
 * so local development can keep its readable emoji output.
 */
class NoEmojiProcessor
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->strip($record->message);
        $context = $this->stripStrings($record->context);
        $extra = $this->stripStrings($record->extra);

        if ($message === $record->message && $context === $record->context && $extra === $record->extra) {
            return $record;
        }

        return $record->with(message: $message, context: $context, extra: $extra);
    }

    private function strip(string $value): string
    {
        // Normalize invalid UTF-8 so JSON formatting never throws.
        $normalized = function_exists('mb_scrub') ? mb_scrub($value, 'UTF-8') : $value;
        $normalized = mb_convert_encoding($normalized, 'UTF-8', 'UTF-8');

        // Emoji / pictographs / variation selectors / regional indicators.
        $stripped = preg_replace('/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $normalized);

        return is_string($stripped) ? $stripped : $normalized;
    }

    private function stripStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $values[$key] = $this->strip($value);
            } elseif (is_array($value)) {
                $values[$key] = $this->stripStrings($value);
            }
        }

        return $values;
    }
}
