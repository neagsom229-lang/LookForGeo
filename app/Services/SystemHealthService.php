<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dependency checks for /api/health. The Gemini probe is cached for 60s so
 * the endpoint never becomes a hot loop of outbound requests.
 *
 * Every private check method MUST wrap its work in try/catch. If any check
 * throws, the caller (makeHealthResponse) returns a 503 "degraded" response
 * with no exception text in the body.
 */
class SystemHealthService
{
    /**
     * Build the health response. If ANY check throws, return 503 degraded
     * with a full map of per-check status — but NEVER leak exception text.
     */
    public function checks(): array
    {
        try {
            $database = $this->database();
            $queue = $this->queue();
            $gemini = $this->gemini();
            $schema = $this->schemaOk();
            $failedJobs = $this->failedJobs();

            $degraded = ! $database['ok'] || $queue['ok'] === false || $gemini['ok'] === false;

            return [
                'status' => $degraded ? 'degraded' : 'operational',
                'started_at' => (string) config('app.started_at', ''),
                'pending_migrations' => $this->pendingMigrations(),
                'schema_ok' => $schema,
                'checks' => [
                    'database' => $database,
                    'queue' => $queue,
                    'gemini' => $gemini,
                    'failed_jobs' => $failedJobs,
                ],
            ];
        } catch (\Throwable $e) {
            // 503 degraded — never leak exception text to the client
            \Log::warning('health_check_fatal', [
                'request_id' => request()->getHttpMethod() . ' ' . request()->getPathInfo(),
                'error' => get_class($e),
            ]);

            return [
                'status' => 'degraded',
                'started_at' => (string) config('app.started_at', ''),
                'pending_migrations' => -1,
                'schema_ok' => false,
                'checks' => [
                    'database' => ['ok' => false, 'error' => 'Health check failed'],
                    'queue' => ['ok' => null, 'backlog' => null, 'oldest_seconds' => null],
                    'gemini' => ['ok' => false, 'error' => 'Health check failed'],
                    'failed_jobs' => ['count' => null],
                ],
            ];
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
        } catch (\Throwable $e) {
            return -1;
        }
    }

    /** Cheap check that the schema this build needs is actually present. */
    private function schemaOk(): bool
    {
        try {
            return Schema::hasTable('users')
                && Schema::hasTable('geo_analyses')
                && Schema::hasColumn('geo_analyses', 'image_hash');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Database unavailable'];
        }
    }

    private function queue(): array
    {
        try {
            $hasJobsTable = $this->jobsTableExists();

            $count = 0;
            $oldest = null;
            $oldestSeconds = null;

            if ($hasJobsTable) {
                $count = (int) DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
            }

            $oldestSeconds = $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : null;

            return [
                'ok' => $oldestSeconds === null || $oldestSeconds < 600,
                'backlog' => $count,
                'oldest_seconds' => $oldestSeconds,
            ];
        } catch (\Throwable $e) {
            return ['ok' => null, 'backlog' => null, 'oldest_seconds' => null];
        }
    }

    private function jobsTableExists(): bool
    {
        try {
            return Schema::hasTable('jobs');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function gemini(): array
    {
        if (! config('services.gemini.health_probe', true)) {
            return ['ok' => null, 'state' => 'disabled'];
        }

        try {
            return (array) Cache::store('array')->remember('health_gemini_reachability', 60, function () {
                $url = rtrim((string) config('services.gemini.base_url'), '/').'/models';

                try {
                    // No key in the URL: a 4xx proves the endpoint is reachable.
                    $response = Http::timeout(3)->connectTimeout(2)->get($url);

                    return ['ok' => $response->status() < 500, 'http_status' => $response->status()];
                } catch (\Throwable $e) {
                    return ['ok' => false, 'error' => 'Gemini endpoint unreachable'];
                }
            });
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Gemini health check failed'];
        }
    }

    private function failedJobs(): array
    {
        try {
            $hasJobsTable = $this->jobsTableExists();

            if (! $hasJobsTable) {
                return ['count' => 0];
            }

            return ['count' => (int) DB::table('failed_jobs')->count()];
        } catch (\Throwable $e) {
            return ['count' => null];
        }
    }
}
