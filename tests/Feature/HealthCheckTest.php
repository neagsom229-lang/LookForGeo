<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    private function emptySqlitePath(): string
    {
        $dir = storage_path('framework/testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir.'/health-empty.sqlite';
        if (file_exists($path)) {
            @unlink($path);
        }
        touch($path);

        return $path;
    }

    private function configureEmptySqlite(string $connection = 'empty'): void
    {
        config(["database.connections.$connection" => [
            'driver' => 'sqlite',
            'database' => $this->emptySqlitePath(),
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        config(['database.default' => $connection]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    public function test_public_health_is_minimal(): void
    {
        config(['services.gemini.health_probe' => false]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'operational', 'migrations_pending' => false]);
    }

    public function test_admin_health_requires_valid_token(): void
    {
        config([
            'services.gemini.health_probe' => false,
            'services.health.admin_token' => 'admin-secret',
        ]);

        $this->getJson('/api/health', ['X-Admin-Token' => 'wrong'])
            ->assertOk()
            ->assertExactJson(['status' => 'operational', 'migrations_pending' => false]);

        $this->getJson('/api/health', ['X-Admin-Token' => 'admin-secret'])
            ->assertOk()
            ->assertJsonPath('status', 'operational')
            ->assertJsonStructure([
                'status',
                'checks' => [
                    'database' => ['ok'],
                    'schema' => ['ok'],
                    'cache' => ['ok'],
                    'queue' => ['ok'],
                ],
                'info' => [
                    'gemini',
                    'migrations_pending',
                    'pending_migrations_count',
                    'failed_jobs',
                ],
            ]);
    }

    public function test_admin_health_does_not_leak_raw_error_messages(): void
    {
        config(['services.health.admin_token' => 'admin-secret']);
        config(['database.connections.bogus' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/to/db.sqlite',
            'prefix' => '',
        ]]);
        config(['database.default' => 'bogus']);
        DB::purge('bogus');

        $response = $this->getJson('/api/health', ['X-Admin-Token' => 'admin-secret'])
            ->assertStatus(503);

        $this->assertSame('degraded', $response->json('status'));
        $this->assertFalse((bool) $response->json('checks.database.ok'));
        $this->assertIsString($response->json('checks.database.error'));
        $this->assertStringNotContainsString('/nonexistent', (string) $response->json('checks.database.error'));
        $this->assertStringNotContainsString('SQLSTATE', json_encode($response->json()));
    }

    public function test_gemini_probe_is_disabled_by_default_and_informational(): void
    {
        config(['services.gemini.health_probe' => false]);
        Http::fake();

        $report = app(SystemHealthService::class)->readiness();

        $this->assertSame('disabled', $report['info']['gemini']['state']);
        $this->assertNull($report['info']['gemini']['ok']);
        $this->assertNotContains('gemini', array_keys($report['checks']));
        Http::assertNothingSent();
    }

    public function test_gemini_probe_failure_does_not_degrade_readiness(): void
    {
        config(['services.gemini.health_probe' => true]);
        Cache::forget('health:gemini-reachability');
        Http::fake([
            '*' => Http::response([], 503),
        ]);

        $report = app(SystemHealthService::class)->readiness();

        $this->assertSame('operational', $report['status']);
        $this->assertFalse($report['info']['gemini']['ok']);
    }

    public function test_gemini_probe_result_is_cached_for_60_seconds(): void
    {
        Cache::forget('health:gemini-reachability');
        config(['services.gemini.health_probe' => true]);

        Http::fakeSequence()
            ->push(['models' => []], 200)
            ->push([], 503);

        $service = app(SystemHealthService::class);

        $first = $service->readiness()['info']['gemini'];
        $second = $service->readiness()['info']['gemini'];

        $this->assertTrue($first['ok']);
        $this->assertSame($first, $second, 'the cached result must be reused');
        Http::assertSentCount(1);
    }

    public function test_health_returns_503_when_database_down(): void
    {
        config(['database.connections.bogus' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/path/to/db.sqlite',
            'prefix' => '',
        ]]);
        config(['database.default' => 'bogus']);
        DB::purge('bogus');

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'migrations_pending' => false]);
    }

    public function test_health_returns_503_when_jobs_table_missing(): void
    {
        $this->configureEmptySqlite();

        Schema::connection('empty')->create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::connection('empty')->create('cache_locks', function ($table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
        Schema::connection('empty')->create('users', function ($table) {
            $table->id();
            $table->timestamps();
        });
        Schema::connection('empty')->create('geo_analyses', function ($table) {
            $table->id();
            $table->string('image_hash')->nullable();
            $table->timestamps();
        });
        Schema::connection('empty')->create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'empty',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'empty',
            'session.driver' => 'database',
            'session.connection' => 'empty',
            'services.gemini.health_probe' => false,
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'migrations_pending' => false]);
    }

    public function test_health_returns_503_when_cache_table_missing(): void
    {
        $this->configureEmptySqlite();

        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('users', function ($table) {
            $table->id();
            $table->timestamps();
        });
        Schema::connection('empty')->create('geo_analyses', function ($table) {
            $table->id();
            $table->string('image_hash')->nullable();
            $table->timestamps();
        });
        Schema::connection('empty')->create('sessions', function ($table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'empty',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'empty',
            'session.driver' => 'database',
            'session.connection' => 'empty',
            'services.gemini.health_probe' => false,
        ]);

        $response = $this->getJson('/api/health');

        $response->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'migrations_pending' => false]);
    }
}
