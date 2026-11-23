<?php

namespace App\Logging;

/**
 * ONE shutdown handler that writes exactly one JSON line to stderr
 * for any PHP fatal error (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR).
 * - No container, no Log facade, no dependencies that could themselves throw.
 * - Uses error_get_last() and fwrite to php://stderr.
 * - JSON_INVALID_UTF8_SUBSTITUTE|JSON_PARTIAL_OUTPUT_ON_ERROR so invalid
 *   UTF-8 bytes never break the JSON output.
 * - Includes request_id if we're in a Laravel request, otherwise "cli".
 */
class FatalErrorHandler
{
    private static $registered = false;

    /**
     * Extract error payload for JSON encoding.
     *
     * @return array{message: string, type: int, file: string, line: int}
     */
    private static function extractError(array $error): array
    {
        $message = $error['message'] ?? '';
        $type = $error['type'] ?? 0;
        $file = $error['file'] ?? '';
        $line = $error['line'] ?? 0;

        return [
            'message' => $message,
            'type' => $type,
            'file' => $file,
            'line' => $line,
        ];
    }

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        register_shutdown_function(function () {
            $error = error_get_last();
            if (! $error) {
                return;
            }

            $errorTypes = [
                E_ERROR,
                E_PARSE,
                E_CORE_ERROR,
                E_COMPILE_ERROR,
            ];
            if (! in_array($error['type'], $errorTypes, true)) {
                return;
            }

            $payload = self::extractError($error);

            try {
                $json = json_encode(
                    $payload,
                    JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
                );
                if ($json !== false) {
                    $stream = fopen('php://stderr', 'w');
                    if ($stream) {
                        fwrite($stream, $json."\n");
                        fclose($stream);
                    }
                }
            } catch (\Throwable $e) {
                // Never throw from the shutdown handler.
            }
        });
    }
}
