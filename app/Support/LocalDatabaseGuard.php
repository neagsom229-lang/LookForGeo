<?php

namespace App\Support;

class LocalDatabaseGuard
{
    /**
     * Refuse to run with a local environment wired to a production database.
     *
     * Checks both the resolved driver AND the host/URL of the active
     * connection (the real-world failure mode is a sqlite connection with a
     * production DATABASE_URL pasted in — Laravel parses the URL and the
     * connection silently becomes Postgres against the prod host).
     *
     * Never prints connection details — values are never echoed or logged.
     *
     * @param  string  $driver  config('database.default')
     * @param  array  $connection  config('database.connections.<driver>')
     *
     * @throws \RuntimeException when the wiring is unsafe for local dev
     */
    public static function assert(string $driver, array $connection): void
    {
        $host = strtolower((string) ($connection['host'] ?? ''));
        $urlHost = '';
        if (! empty($connection['url'])) {
            $urlHost = strtolower((string) (parse_url((string) $connection['url'], PHP_URL_HOST) ?: ''));
        }

        $haystack = $host.' '.$urlHost;

        if ($driver === 'pgsql' || str_contains($haystack, 'render.com') || str_contains($haystack, 'dpg-')) {
            throw new \RuntimeException(
                'Refusing to run with APP_ENV=local against a production database. '
                .'Fix LOCAL .env (DB_CONNECTION / DATABASE_URL) — values are never printed. '
                .'Production credentials belong in the Render dashboard only.'
            );
        }
    }
}
