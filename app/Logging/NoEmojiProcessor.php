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
        // Emoji / pictographs / variation selectors / regional indicators.
        return (string) preg_replace('/[\x{1F000}-\x{1FAFF}\x{1F1E6}-\x{1F1FF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $value);
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
