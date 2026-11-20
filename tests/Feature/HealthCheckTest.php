<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_reports_all_dependency_checks(): void
    {
        $response = $this->getJson('/api/health')->assertOk();

        $response->assertJsonPath('success', true)
            ->assertJsonPath('status', 'operational')
            ->assertJsonStructure([
                'checks' => [
                    'database' => ['ok'],
                    'queue' => ['ok', 'backlog', 'oldest_seconds'],
                    'gemini' => ['ok'],
                    'failed_jobs' => ['count'],
                ],
            ]);
        $this->assertTrue($response->json('checks.database.ok'));
        $this->assertSame('disabled', $response->json('checks.gemini.state'));
    }

    public function test_gemini_probe_result_is_cached_for_60_seconds(): void
    {
        Cache::forget('health_gemini_reachability');
        config(['services.gemini.health_probe' => true]);

        Http::fakeSequence()
            ->push(['models' => []], 200)
            ->push([], 503);

        $service = app(SystemHealthService::class);

        $first = $service->checks()['checks']['gemini'];
        $second = $service->checks()['checks']['gemini'];

        $this->assertTrue($first['ok']);
        $this->assertSame($first, $second, 'the cached result must be reused');
        Http::assertSentCount(1);
    }
}
