<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Dependency checks for /api/health. The Gemini probe is cached for 60s so
 * the endpoint never becomes a hot loop of outbound requests.
 */
class SystemHealthService
{
    public function checks(): array
    {
        $database = $this->database();
        $queue = $this->queue();
        $gemini = $this->gemini();

        $degraded = ! $database['ok'] || $queue['ok'] === false || $gemini['ok'] === false;

        return [
            'status' => $degraded ? 'degraded' : 'operational',
            'started_at' => (string) config('app.started_at', ''),
            'pending_migrations' => $this->pendingMigrations(),
            'schema_ok' => $this->schemaOk(),
            'checks' => [
                'database' => $database,
                'queue' => $queue,
                'gemini' => $gemini,
                'failed_jobs' => $this->failedJobs(),
            ],
        ];
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
        if (! Schema::hasTable('jobs')) {
            return ['ok' => null, 'backlog' => 0, 'oldest_seconds' => null];
        }

        try {
            $count = (int) DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
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

    private function gemini(): array
    {
        if (! config('services.gemini.health_probe', true)) {
            return ['ok' => null, 'state' => 'disabled'];
        }

        return (array) Cache::remember('health_gemini_reachability', 60, function () {
            $url = rtrim((string) config('services.gemini.base_url'), '/').'/models';

            try {
                // No key in the URL: a 4xx proves the endpoint is reachable.
                $response = Http::timeout(3)->connectTimeout(2)->get($url);

                return ['ok' => $response->status() < 500, 'http_status' => $response->status()];
            } catch (\Throwable $e) {
                return ['ok' => false, 'error' => 'Gemini endpoint unreachable'];
            }
        });
    }

    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['count' => 0];
        }

        try {
            return ['count' => (int) DB::table('failed_jobs')->count()];
        } catch (\Throwable $e) {
            return ['count' => null];
        }
    }
}
