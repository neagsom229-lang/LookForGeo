<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Cache;

/**
 * Per-model circuit breaker for quota/overload/timeout failures. State lives
 * in the configured cache store (database in production), so web and worker
 * see the same state. A hung or overloaded primary opens only its own key;
 * the fallback model stays usable.
 */
final class GeminiCircuitBreaker
{
    private const PREFIX = 'gemini_circuit_breaker:';

    private const INDEX = 'gemini_circuit_breaker:index';

    public function isOpen(string $model): bool
    {
        $state = $this->state($model);

        return ($state['open_until'] ?? 0) > time();
    }

    public function remainingSeconds(string $model): int
    {
        $state = $this->state($model);

        return max(0, (int) (($state['open_until'] ?? 0) - time()));
    }

    public function recordFailure(string $model, ?int $retryAfter = null): void
    {
        $state = $this->state($model);
        $state['failures'] = (int) ($state['failures'] ?? 0) + 1;
        $threshold = max(1, (int) config('services.gemini.circuit_failures', 3));

        if ($state['failures'] >= $threshold) {
            $seconds = max(
                (int) config('services.gemini.circuit_open_seconds', 60),
                (int) ($retryAfter ?? 0)
            );
            $state['open_until'] = time() + $seconds;
            $state['failures'] = 0;
        }

        $this->save($model, $state);
    }

    public function recordSuccess(string $model): void
    {
        $this->save($model, ['failures' => 0, 'open_until' => 0]);
    }

    public function reset(?string $model = null): void
    {
        if ($model !== null) {
            Cache::forget($this->key($model));

            return;
        }

        foreach ((array) Cache::get(self::INDEX, []) as $key) {
            Cache::forget((string) $key);
        }
        Cache::forget(self::INDEX);
    }

    private function key(string $model): string
    {
        return self::PREFIX.sha1($model);
    }

    private function state(string $model): array
    {
        $state = Cache::get($this->key($model));

        return is_array($state) ? $state : ['failures' => 0, 'open_until' => 0];
    }

    private function save(string $model, array $state): void
    {
        $key = $this->key($model);
        $index = (array) Cache::get(self::INDEX, []);
        if (! in_array($key, $index, true)) {
            $index[] = $key;
            Cache::put(self::INDEX, $index, now()->addDay());
        }

        $ttl = max(60, (int) config('services.gemini.circuit_open_seconds', 60) * 2);
        Cache::put($key, $state, now()->addSeconds($ttl));
    }
}
