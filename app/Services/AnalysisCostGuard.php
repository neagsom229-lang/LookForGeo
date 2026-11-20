<?php

namespace App\Services;

use App\Models\GeoAnalysis;
use Illuminate\Support\Facades\Cache;

/**
 * Cost guard for the paid Gemini pipeline.
 *
 * - per-user daily quota (default 50, configurable via GEMINI_DAILY_PER_USER)
 * - global kill switch (GEMINI_DAILY_LIMIT, 0 = disabled)
 *
 * Cached repeat uploads are free and bypass the guard entirely: the caller
 * checks the sha256 cache first and only calls blocked()/record() for a real
 * analysis.
 */
class AnalysisCostGuard
{
    /**
     * @return array{message: string, retry_after: int}|null null when allowed
     */
    public function blocked(int $userId): ?array
    {
        $global = (int) config('services.gemini.daily_limit_global', 0);
        if ($global > 0 && GeoAnalysis::whereDate('created_at', today())->count() >= $global) {
            return [
                'message' => 'The daily analysis limit has been reached. Please try again tomorrow.',
                'retry_after' => 86400,
            ];
        }

        $perUser = (int) config('services.gemini.daily_limit_per_user', 50);
        if ($perUser > 0 && (int) Cache::get($this->key($userId), 0) >= $perUser) {
            return [
                'message' => "You have reached your daily analysis limit ({$perUser}). Please try again tomorrow.",
                'retry_after' => 86400,
            ];
        }

        return null;
    }

    public function record(int $userId): void
    {
        if ((int) config('services.gemini.daily_limit_per_user', 50) <= 0) {
            return;
        }

        $key = $this->key($userId);
        if (! Cache::has($key)) {
            Cache::put($key, 0, now()->endOfDay());
        }
        Cache::increment($key);
    }

    private function key(int $userId): string
    {
        return 'analysis_daily:'.$userId.':'.now()->toDateString();
    }
}
