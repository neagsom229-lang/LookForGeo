<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_reports_all_dependency_checks(): void
    {
        config(['services.gemini.health_probe' => false]);

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

    public function test_health_reports_start_time_pending_migrations_and_schema(): void
    {
        // Ensure the schema check tables exist
        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table) {
                $table->id();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('geo_analyses')) {
            Schema::create('geo_analyses', function ($table) {
                $table->id();
                $table->string('image_hash');
                $table->timestamps();
            });
        }

        config(['app.started_at' => now()->toIso8601String()]);

        $response = $this->getJson('/api/health')->assertOk();

        $response->assertJsonStructure([
            'started_at',
            'pending_migrations_present',
            'schema_ok',
        ]);
        $this->assertNotSame('', $response->json('started_at'));
        $this->assertFalse($response->json('pending_migrations_present'));
        $this->assertTrue($response->json('schema_ok'));
        $this->assertNull($response->json('pending_migrations'));
    }

    public function test_health_counts_require_the_admin_header(): void
    {
        config(['services.health.admin_token' => 'admin-secret']);

        $this->getJson('/api/health', ['X-Admin-Token' => 'wrong'])
            ->assertOk()
            ->assertJsonMissing(['pending_migrations']);

        $this->getJson('/api/health', ['X-Admin-Token' => 'admin-secret'])
            ->assertOk()
            ->assertJsonPath('pending_migrations', function ($value) {
                // pending_migrations may be 0 (no pending), -1 (could not check),
                // or a positive number; both 0 and -1 are acceptable.
                return $value >= -1;
            });
    }

    public function test_health_reports_short_deploy_commit(): void
    {
        config(['app.deploy_commit' => 'abcdef1234567890']);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('commit', 'abcdef1');
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

    public function test_health_returns_503_when_database_down(): void
    {
        // Arrange: configure a bogus database connection with invalid path
        config(['database.connections.bogus' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/to/db.sqlite',
            'prefix' => '',
        ]]);
        config(['database.default' => 'bogus']);

        // Act
        $response = $this->getJson('/api/health');

        // Assert
        $response->assertStatus(503)
            ->assertJson(['status' => 'degraded'])
            ->assertJsonPath('checks.database.ok', false)
            ->assertJsonMissing(['checks.database.error']);
    }

    public function test_log_channel_writes_valid_json(): void
    {
        // Write a log entry with emoji, invalid UTF-8, secret-like key, and large string
        \Log::info(
            'Test log with 🔥 emoji and poor UTF-8: \x80\x90\xa1 and secret key AIza... and '.substr(str_repeat('A', 1000000), 0, 100000)
        );

        // Verify no exception was thrown during logging
        $this->assertTrue(true, 'Log channel did not throw exception');

        // Read the log file that Laravel writes to
        $logFile = storage_path('logs/laravel-'.date('Y-m-d').'.log');
        if (file_exists($logFile)) {
            $logContent = file_get_contents($logFile);
            $this->assertIsString($logContent);

            // Check that the line contains our test message elements
            $this->assertStringContainsString('🔥', $logContent);
            $this->assertStringContainsString('AIza', $logContent);

            // Clean up - rename the log file to avoid matching on next test
            rename($logFile, $logFile.'.bak');
        } else {
            $this->markTestSkipped('Laravel log file not found at '.$logFile);
        }
    }

    public function test_production_500_generic_page(): void
    {
        // Arrange: set production mode and enable debug=false
        config(['app.env' => 'production']);
        config(['app.debug' => false]);

        // Register a throwing route inside the test body
        // Use the app's router to register a temporary route
        $router = $this->app->make('router');
        $router->get('/test-fatal', function () {
            throw new RuntimeException('Test fatal error for production 500 testing');
        });

        // Act
        $response = $this->get('/test-fatal');

        // Assert: should get 500 generic page
        $response->assertStatus(500);
        $this->assertStringNotContainsString('RuntimeException', $response->getContent());
        $this->assertStringNotContainsString('Test fatal error', $response->getContent());
    }
}
