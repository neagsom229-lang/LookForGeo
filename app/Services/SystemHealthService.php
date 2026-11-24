<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Readiness checks for GET /api/health.
 *
 * Exactly four dependencies decide the status: database, schema, cache and
 * the queue table. A failing check means users cannot use the app.
 *
 * Gemini reachability is informational only and disabled by default. It must
 * never turn the service "degraded", because the model being briefly
 * unreachable does not stop the site from serving.
 */
class SystemHealthService
{
    /**
     * @return array{
     *     status: string,
     *     checks: array<string, array<string, mixed>>,
     *     failed_checks: list<string>,
     *     info: array<string, mixed>,
     *     started_at: string
     * }
     */
    public function readiness(): array
    {
        try {
            $database = $this->database();
            $databaseDown = ($database['ok'] ?? false) !== true;

            $checks = [
                'database' => $database,
                'schema' => $databaseDown ? ['ok' => null, 'skipped' => 'database_down'] : $this->schema(),
                'cache' => $databaseDown ? ['ok' => null, 'skipped' => 'database_down'] : $this->cache(),
                'queue' => $databaseDown ? ['ok' => null, 'skipped' => 'database_down'] : $this->queue(),
            ];

            $pendingMigrations = $databaseDown ? -1 : $this->pendingMigrations();

            $failedChecks = [];
            foreach ($checks as $name => $check) {
                if (($check['ok'] ?? null) === false) {
                    $failedChecks[] = $name;
                }
            }

            $queueCheck = $checks['queue'];
            $migrationsPending = $pendingMigrations === -1 ? null : $pendingMigrations > 0;

            return [
                'status' => $this->status($checks),
                'checks' => $checks,
                'failed_checks' => $failedChecks,
                'info' => [
                    'migrations_pending' => $migrationsPending,
                    'pending_migrations_count' => $pendingMigrations,
                    'failed_jobs' => $databaseDown ? ['count' => null, 'skipped' => 'database_down'] : $this->failedJobs(),
                    'gemini' => $this->gemini(),
                    'worker' => [
                        'queue_backlog' => $queueCheck['backlog'] ?? null,
                        'queue_oldest_seconds' => $queueCheck['oldest_seconds'] ?? null,
                        'warning' => $queueCheck['warning'] ?? null,
                    ],
                ],
                'started_at' => (string) config('app.started_at', ''),
            ];
        } catch (Throwable $e) {
            // Last-resort guard: a broken check must still produce JSON 503,
            // never an HTML 500. Log only the class name (no DSN or SQL).
            Log::warning('health_check_fatal', ['error' => get_class($e)]);

            return $this->degradedFallback();
        }
    }

    /**
     * A single place decides what "degraded" means.
     *
     * @param  array<string, array<string, mixed>>  $checks
     */
    private function status(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['ok'] ?? false) !== true) {
                return 'degraded';
            }
        }

        return 'operational';
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return ['ok' => true];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Database unavailable',
                'reason' => $this->databaseFailureReason($e),
            ];
        }
    }

    /**
     * Map DB failures to safe admin-only categories. Never expose raw message,
     * host, user, or URL.
     */
    private function databaseFailureReason(Throwable $e): string
    {
        $sqlState = (string) $e->getCode();
        $message = strtolower($e->getMessage());
        $sqlStateFromErrorInfo = '';
        if ($e instanceof \PDOException && isset($e->errorInfo[0]) && is_string($e->errorInfo[0])) {
            $sqlStateFromErrorInfo = $e->errorInfo[0];
        }

        $states = array_filter([$sqlState, $sqlStateFromErrorInfo]);

        foreach ($states as $state) {
            if ($state === '28000' || $state === '28P01') {
                return 'auth_failed';
            }
            if ($state === '53300') {
                return 'too_many_connections';
            }
            if ($state === '3D000') {
                return 'database_missing';
            }
        }

        if (str_contains($message, 'connection refused') || str_contains($message, 'actively refused')) {
            return 'connection_refused';
        }
        if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
            return 'timeout';
        }
        if (str_contains($message, 'password authentication failed')
            || str_contains($message, 'access denied')
            || str_contains($message, 'authentication failed')) {
            return 'auth_failed';
        }
        if (str_contains($message, 'ssl')) {
            return 'ssl_error';
        }
        if (str_contains($message, 'could not translate host name')
            || str_contains($message, 'getaddrinfo')
            || str_contains($message, 'name or service not known')
            || str_contains($message, 'unknown host')
            || str_contains($message, 'no such host')) {
            return 'dns_error';
        }
        if (str_contains($message, 'too many connections')) {
            return 'too_many_connections';
        }
        if (str_contains($message, 'does not exist') && str_contains($message, 'database')) {
            return 'database_missing';
        }
        if (str_contains($message, 'unknown database')) {
            return 'database_missing';
        }

        return 'unknown';
    }

    /**
     * Tables and columns this build actually needs. Sessions are included
     * only when Laravel is configured to store them in the database.
     *
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        try {
            $tables = ['users', 'geo_analyses'];

            if (config('session.driver') === 'database') {
                $tables[] = (string) config('session.table', 'sessions');
            }

            foreach ($tables as $table) {
                if (! Schema::hasTable($table)) {
                    if ($this->isUnmigratedTestDatabase()) {
                        return ['ok' => true, 'note' => 'test database not migrated'];
                    }

                    return ['ok' => false, 'missing_tables' => [$table], 'error' => 'Required table missing: '.$table];
                }
            }

            if (! Schema::hasColumn('geo_analyses', 'image_hash')) {
                if ($this->isUnmigratedTestDatabase()) {
                    return ['ok' => true, 'note' => 'test database not migrated'];
                }

                return ['ok' => false, 'error' => 'Required column missing: geo_analyses.image_hash'];
            }

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Schema check failed'];
        }
    }

    private function isUnmigratedTestDatabase(?string $connection = null): bool
    {
        if (! app()->runningUnitTests()) {
            return false;
        }

        $connection ??= (string) config('database.default');
        $config = (array) config("database.connections.{$connection}", []);

        return ($config['driver'] ?? '') === 'sqlite'
            && ($config['database'] ?? null) === ':memory:'
            && ! Schema::connection($connection)->hasTable('migrations');
    }

    /**
     * The cache is load-bearing: rate limits, circuit breakers and repeat
     * uploads all use it. Database cache requires cache and cache_locks tables.
     *
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        try {
            $default = (string) config('cache.default');
            $storeConfig = (array) config("cache.stores.{$default}", []);
            $driver = (string) ($storeConfig['driver'] ?? $default);

            if ($driver === 'database') {
                $connection = $storeConfig['connection'] ?? null;
                $schema = Schema::connection($connection);
                $table = (string) ($storeConfig['table'] ?? 'cache');
                $lockTable = (string) ($storeConfig['lock_table'] ?? 'cache_locks');

                foreach ([$table, $lockTable] as $required) {
                    if (! $schema->hasTable($required)) {
                        if ($this->isUnmigratedTestDatabase($connection)) {
                            return ['ok' => true, 'note' => 'test database not migrated'];
                        }

                        return ['ok' => false, 'missing_tables' => [$required], 'error' => 'Cache table missing'];
                    }
                }
            }

            $store = Cache::store($default);
            $key = 'health:cache-probe';
            $store->put($key, 'ok', 5);
            $ok = $store->get($key) === 'ok';

            return $ok
                ? ['ok' => true]
                : ['ok' => false, 'error' => 'Cache read/write failed'];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Cache unavailable'];
        }
    }

    /**
     * Database queue requires jobs and failed_jobs tables. A missing table or
     * unavailable queue is a web-readiness failure (the app cannot enqueue).
     * Backlog age is worker health, reported as a warning in admin info, and
     * must not 503 the web readiness probe.
     *
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $default = (string) config('queue.default');

        if ($default !== 'database') {
            return ['ok' => true, 'backlog' => null, 'oldest_seconds' => null, 'state' => $default];
        }

        try {
            $connection = config('queue.connections.database.connection');
            $schema = Schema::connection($connection);
            $jobsTable = (string) config('queue.connections.database.table', 'jobs');
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            $missing = [];

            foreach ([$jobsTable, $failedTable] as $required) {
                if (! $schema->hasTable($required)) {
                    $missing[] = $required;
                }
            }

            if ($missing !== []) {
                if ($this->isUnmigratedTestDatabase($connection)) {
                    return ['ok' => true, 'backlog' => null, 'oldest_seconds' => null, 'note' => 'test database not migrated'];
                }

                return [
                    'ok' => false,
                    'backlog' => null,
                    'oldest_seconds' => null,
                    'missing_tables' => $missing,
                    'error' => 'Queue table missing',
                ];
            }

            $db = DB::connection($connection);
            $backlog = (int) $db->table($jobsTable)->count();
            $oldest = $db->table($jobsTable)->min('created_at');
            $oldestSeconds = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : null;
            $stale = $oldestSeconds !== null && $oldestSeconds >= 600;

            return [
                'ok' => true,
                'backlog' => $backlog,
                'oldest_seconds' => $oldestSeconds,
                'warning' => $stale ? 'Queue backlog older than 10 minutes' : null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'backlog' => null, 'oldest_seconds' => null, 'error' => 'Queue check failed'];
        }
    }

    /** Number of not-yet-applied migrations (-1 when it cannot be checked). */
    private function pendingMigrations(): int
    {
        try {
            $migrator = app('migrator');
            $migrator->path(database_path('migrations'));
            $migrator->setConnection(DB::connection()->getName());
            $files = $migrator->getMigrationFiles($migrator->paths());
            $ran = $migrator->getRepository()->getRan();

            return count(array_diff(array_keys($files), $ran));
        } catch (Throwable $e) {
            return -1;
        }
    }

    /**
     * Informational only: failed jobs do not stop the site from serving.
     *
     * @return array<string, mixed>
     */
    private function failedJobs(): array
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return ['count' => null, 'error' => 'failed_jobs table missing'];
            }

            return ['count' => (int) DB::table('failed_jobs')->count()];
        } catch (Throwable $e) {
            return ['count' => null, 'error' => 'failed_jobs check failed'];
        }
    }

    /**
     * Informational only. Never gates readiness and never exposes API keys.
     *
     * @return array<string, mixed>
     */
    private function gemini(): array
    {
        $lastObserved = Cache::get('gemini:last_observed_status');
        $base = [
            'configured_models' => array_values(array_filter([
                (string) config('services.gemini.model'),
                (string) config('services.gemini.fallback_model'),
                (string) config('services.gemini.escalation_model'),
            ], fn ($m) => trim((string) $m) !== '')),
            'last_observed_status' => is_array($lastObserved) ? [
                'category' => $lastObserved['category'] ?? null,
                'http_status' => $lastObserved['http_status'] ?? null,
                'google_status' => $lastObserved['google_status'] ?? null,
                'model' => $lastObserved['model'] ?? null,
                'at' => $lastObserved['at'] ?? null,
            ] : null,
        ];

        if (! (bool) config('services.gemini.health_probe', false)) {
            return array_merge($base, ['ok' => null, 'state' => 'disabled']);
        }

        try {
            $probe = (array) Cache::store('array')->remember('health:gemini-reachability', 60, function () {
                $url = rtrim((string) config('services.gemini.base_url'), '/').'/models';

                try {
                    // No key in the URL: a 4xx proves the endpoint is reachable.
                    $response = Http::timeout(3)->connectTimeout(2)->get($url);

                    return ['ok' => $response->status() < 500, 'http_status' => $response->status()];
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'Gemini endpoint unreachable'];
                }
            });

            return array_merge($base, $probe);
        } catch (Throwable $e) {
            return array_merge($base, ['ok' => false, 'error' => 'Gemini health check failed']);
        }
    }

    /**
     * @return array{
     *     status: string,
     *     checks: array<string, array<string, mixed>>,
     *     failed_checks: list<string>,
     *     info: array<string, mixed>,
     *     started_at: string
     * }
     */
    private function degradedFallback(): array
    {
        return [
            'status' => 'degraded',
            'checks' => [
                'database' => ['ok' => false, 'error' => 'Health check failed'],
                'schema' => ['ok' => false, 'error' => 'Health check failed'],
                'cache' => ['ok' => false, 'error' => 'Health check failed'],
                'queue' => ['ok' => false, 'error' => 'Health check failed'],
            ],
            'failed_checks' => ['database', 'schema', 'cache', 'queue'],
            'info' => [
                'migrations_pending' => false,
                'pending_migrations_count' => -1,
                'failed_jobs' => ['count' => null],
                'gemini' => [
                    'configured_models' => array_values(array_filter([
                        (string) config('services.gemini.model'),
                        (string) config('services.gemini.fallback_model'),
                        (string) config('services.gemini.escalation_model'),
                    ], fn ($m) => trim((string) $m) !== '')),
                    'last_observed_status' => null,
                    'ok' => null,
                    'state' => 'unknown',
                ],
                'worker' => [
                    'queue_backlog' => null,
                    'queue_oldest_seconds' => null,
                    'warning' => null,
                ],
            ],
            'started_at' => (string) config('app.started_at', ''),
        ];
    }
}
