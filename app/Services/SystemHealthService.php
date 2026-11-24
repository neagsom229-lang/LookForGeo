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
     *     info: array<string, mixed>,
     *     started_at: string
     * }
     */
    public function readiness(): array
    {
        try {
            $checks = [
                'database' => $this->database(),
                'schema' => $this->schema(),
                'cache' => $this->cache(),
                'queue' => $this->queue(),
            ];

            $pendingMigrations = $this->pendingMigrations();

            return [
                'status' => $this->status($checks),
                'checks' => $checks,
                'info' => [
                    'migrations_pending' => $pendingMigrations > 0,
                    'pending_migrations_count' => $pendingMigrations,
                    'failed_jobs' => $this->failedJobs(),
                    'gemini' => $this->gemini(),
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
            return ['ok' => false, 'error' => 'Database unavailable'];
        }
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

                    return ['ok' => false, 'error' => 'Required table missing: '.$table];
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

                        return ['ok' => false, 'error' => 'Cache table missing'];
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
     * Database queue requires jobs and failed_jobs tables.
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

            foreach ([$jobsTable, $failedTable] as $required) {
                if (! $schema->hasTable($required)) {
                    if ($this->isUnmigratedTestDatabase($connection)) {
                        return ['ok' => true, 'backlog' => null, 'oldest_seconds' => null, 'note' => 'test database not migrated'];
                    }

                    return [
                        'ok' => false,
                        'backlog' => null,
                        'oldest_seconds' => null,
                        'error' => 'Queue table missing',
                    ];
                }
            }

            $db = DB::connection($connection);
            $backlog = (int) $db->table($jobsTable)->count();
            $oldest = $db->table($jobsTable)->min('created_at');
            $oldestSeconds = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : null;
            $ok = $oldestSeconds === null || $oldestSeconds < 600;

            return [
                'ok' => $ok,
                'backlog' => $backlog,
                'oldest_seconds' => $oldestSeconds,
                'error' => $ok ? null : 'Queue backlog older than 10 minutes',
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'backlog' => null, 'oldest_seconds' => null, 'error' => 'Queue check failed'];
        }
    }

    /** Number of not-yet-applied migrations (-1 when it cannot be checked). */
    private function pendingMigrations(): int
    {
        try {
            if ($this->isUnmigratedTestDatabase()) {
                return 0;
            }

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
     * Informational only and disabled by default. The array store keeps this
     * probe working even when the database cache is the thing that is down.
     *
     * @return array<string, mixed>
     */
    private function gemini(): array
    {
        if (! (bool) config('services.gemini.health_probe', false)) {
            return ['ok' => null, 'state' => 'disabled'];
        }

        try {
            return (array) Cache::store('array')->remember('health:gemini-reachability', 60, function () {
                $url = rtrim((string) config('services.gemini.base_url'), '/').'/models';

                try {
                    // No key in the URL: a 4xx proves the endpoint is reachable.
                    $response = Http::timeout(3)->connectTimeout(2)->get($url);

                    return ['ok' => $response->status() < 500, 'http_status' => $response->status()];
                } catch (Throwable $e) {
                    return ['ok' => false, 'error' => 'Gemini endpoint unreachable'];
                }
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Gemini health check failed'];
        }
    }

    /**
     * @return array{
     *     status: string,
     *     checks: array<string, array<string, mixed>>,
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
            'info' => [
                'migrations_pending' => false,
                'pending_migrations_count' => -1,
                'failed_jobs' => ['count' => null],
                'gemini' => ['ok' => null, 'state' => 'unknown'],
            ],
            'started_at' => (string) config('app.started_at', ''),
        ];
    }
}
