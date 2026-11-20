<?php

namespace App\Services\Gemini;

use Illuminate\Support\Facades\Cache;

/**
 * Small shared circuit breaker for quota/overload failures. State lives in
 * the configured cache store (database in production), so the web process
 * and the queue worker see the same state.
 */
final class GeminiCircuitBreaker
{
    private const KEY = 'gemini_circuit_breaker';

    public function isOpen(): bool
    {
        $state = $this->state();

        return ($state['open_until'] ?? 0) > time();
    }

    public function remainingSeconds(): int
    {
        $state = $this->state();

        return max(0, (int) (($state['open_until'] ?? 0) - time()));
    }

    public function recordFailure(?int $retryAfter = null): void
    {
        $state = $this->state();
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

        $this->save($state);
    }

    public function recordSuccess(): void
    {
        $this->save(['failures' => 0, 'open_until' => 0]);
    }

    public function reset(): void
    {
        Cache::forget(self::KEY);
    }

    private function state(): array
    {
        $state = Cache::get(self::KEY);

        return is_array($state) ? $state : ['failures' => 0, 'open_until' => 0];
    }

    private function save(array $state): void
    {
        $ttl = max(60, (int) config('services.gemini.circuit_open_seconds', 60) * 2);
        Cache::put(self::KEY, $state, now()->addSeconds($ttl));
    }
}
