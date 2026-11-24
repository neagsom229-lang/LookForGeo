<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /** @var list<string> */
    private array $tempSqliteFiles = [];

    protected function tearDown(): void
    {
        foreach (array_unique(array_merge(['empty'], array_keys(config('database.connections', [])))) as $connection) {
            if (is_string($connection)) {
                try {
                    DB::purge($connection);
                } catch (\Throwable $e) {
                }
            }
        }

        foreach ($this->tempSqliteFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempSqliteFiles = [];

        parent::tearDown();
    }

    private function emptySqlitePath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lg-health-');
        if ($path === false) {
            $this->fail('Could not create temp sqlite file');
        }
        $this->tempSqliteFiles[] = $path;

        return $path;
    }

    private function configureEmptySqlite(string $connection = 'empty'): void
    {
        $path = $this->emptySqlitePath();
        config(["database.connections.$connection" => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        config(['database.default' => $connection]);
        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    public function test_public_health_is_minimal(): void
    {
        Http::preventStrayRequests();
        config(['services.gemini.health_probe' => false]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'operational']);
    }

    public function test_up_returns_commit_header(): void
    {
        config(['app.deploy_commit' => 'abcdef1234567890']);

        $this->get('/up')
            ->assertOk()
            ->assertHeader('X-App-Commit', 'abcdef1');
    }

    public function test_admin_health_requires_valid_token(): void
    {
        config([
            'services.gemini.health_probe' => false,
            'services.health.admin_token' => 'admin-secret',
        ]);

        $this->getJson('/api/health', ['X-Admin-Token' => 'wrong'])
            ->assertOk()
            ->assertExactJson(['status' => 'operational']);

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
        Http::preventStrayRequests();
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
            ->assertExactJson(['status' => 'degraded']);
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
            ->assertExactJson(['status' => 'degraded']);
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
            ->assertExactJson(['status' => 'degraded']);
    }

    public function test_health_returns_503_when_sessions_table_missing(): void
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
        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('failed_jobs', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
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
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'empty', 'cache.default' => 'database', 'cache.stores.database.connection' => 'empty', 'session.driver' => 'database', 'session.connection' => 'empty', 'services.gemini.health_probe' => false]);
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    }

    public function test_health_returns_503_when_cache_locks_table_missing(): void
    {
        $this->configureEmptySqlite();
        Schema::connection('empty')->create('cache', function ($table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('failed_jobs', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
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
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'empty', 'cache.default' => 'database', 'cache.stores.database.connection' => 'empty', 'session.driver' => 'database', 'session.connection' => 'empty', 'services.gemini.health_probe' => false]);
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    }

    public function test_health_returns_503_when_failed_jobs_table_missing(): void
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
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'empty', 'cache.default' => 'database', 'cache.stores.database.connection' => 'empty', 'session.driver' => 'database', 'session.connection' => 'empty', 'services.gemini.health_probe' => false]);
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    }

    public function test_health_returns_503_when_users_table_missing(): void
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
        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('failed_jobs', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
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
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'empty', 'cache.default' => 'database', 'cache.stores.database.connection' => 'empty', 'session.driver' => 'database', 'session.connection' => 'empty', 'services.gemini.health_probe' => false]);
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    }

    public function test_health_returns_503_when_geo_analyses_table_missing(): void
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
        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('failed_jobs', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
        Schema::connection('empty')->create('users', function ($table) {
            $table->id();
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
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'empty', 'cache.default' => 'database', 'cache.stores.database.connection' => 'empty', 'session.driver' => 'database', 'session.connection' => 'empty', 'services.gemini.health_probe' => false]);
        $this->getJson('/api/health')->assertStatus(503)->assertExactJson(['status' => 'degraded']);
    }

    public function test_admin_health_includes_failed_checks_and_no_raw_exception_text(): void
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
            'queue.failed.table' => 'failed_jobs',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'empty',
            'session.driver' => 'database',
            'session.connection' => 'empty',
            'services.health.admin_token' => 'test-admin-token',
            'services.gemini.health_probe' => false,
        ]);

        $response = $this->withHeader('X-Admin-Token', 'test-admin-token')->getJson('/api/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('failed_checks.0', 'queue')
            ->assertJsonPath('checks.queue.ok', false)
            ->assertJsonPath('checks.queue.missing_tables.0', 'failed_jobs')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.schema.ok', true)
            ->assertJsonPath('checks.cache.ok', true);

        $this->assertIsArray($response->json('failed_checks'));
        $this->assertArrayHasKey('queue_backlog', $response->json('info.worker'));
        $this->assertArrayHasKey('queue_oldest_seconds', $response->json('info.worker'));

        $raw = json_encode($response->json());
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('Exception', $raw);
        $this->assertStringNotContainsString('Stack trace', $raw);
        $this->assertStringNotContainsString('SQLSTATE', $raw);
    }

    public function test_stalled_queue_worker_does_not_degrade_web_readiness(): void
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
        Schema::connection('empty')->create('jobs', function ($table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::connection('empty')->create('failed_jobs', function ($table) {
            $table->id();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
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

        DB::connection('empty')->table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->getTimestamp(),
            'created_at' => now()->subMinutes(20)->getTimestamp(),
        ]);

        config([
            'queue.default' => 'database',
            'queue.connections.database.connection' => 'empty',
            'cache.default' => 'database',
            'cache.stores.database.connection' => 'empty',
            'session.driver' => 'database',
            'session.connection' => 'empty',
            'services.health.admin_token' => 'test-admin-token',
            'services.gemini.health_probe' => false,
        ]);

        $public = $this->getJson('/api/health');
        $public->assertStatus(200)->assertExactJson(['status' => 'operational']);

        $admin = $this->withHeader('X-Admin-Token', 'test-admin-token')->getJson('/api/health');
        $admin->assertStatus(200)
            ->assertJsonPath('status', 'operational')
            ->assertJsonPath('checks.queue.ok', true)
            ->assertJsonPath('failed_checks', []);

        $this->assertSame(1, $admin->json('checks.queue.backlog'));
        $this->assertGreaterThanOrEqual(600, $admin->json('checks.queue.oldest_seconds'));
        $this->assertSame('Queue backlog older than 10 minutes', $admin->json('checks.queue.warning'));
        $this->assertSame('Queue backlog older than 10 minutes', $admin->json('info.worker.warning'));
        $this->assertSame(1, $admin->json('info.worker.queue_backlog'));
    }

    #[DataProvider('databaseFailureCategories')]
    public function test_database_failure_reason_is_classified_in_admin_payload(string $sqlState, int $driverCode, string $message, string $expected): void
    {
        $e = new \PDOException($message, $driverCode);
        $e->errorInfo = [$sqlState, $driverCode, $message];

        DB::partialMock()->shouldReceive('connection')->andThrow($e);

        config(['services.health.admin_token' => 'test-admin-token', 'services.gemini.health_probe' => false]);

        $public = $this->getJson('/api/health');
        $public->assertStatus(503)->assertExactJson(['status' => 'degraded']);

        $admin = $this->withHeader('X-Admin-Token', 'test-admin-token')->getJson('/api/health');
        $admin->assertStatus(503)
            ->assertJsonPath('checks.database.ok', false)
            ->assertJsonPath('checks.database.reason', $expected)
            ->assertJsonPath('failed_checks', ['database']);

        $raw = json_encode($admin->json());
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('secret-host', $raw);
        $this->assertStringNotContainsString('secret_user', $raw);
        $this->assertStringNotContainsString('postgres://', $raw);
    }

    public static function databaseFailureCategories(): array
    {
        return [
            'connection_refused' => ['HY000', 2002, 'SQLSTATE[HY000] [2002] Connection refused for user secret_user@secret-host', 'connection_refused'],
            'timeout' => ['HY000', 2002, 'SQLSTATE[HY000] [2002] Operation timed out after 5000 milliseconds', 'timeout'],
            'auth_failed' => ['28P01', 7, 'SQLSTATE[28P01] FATAL: password authentication failed for user "secret_user"', 'auth_failed'],
            'ssl_error' => ['08006', 0, 'SQLSTATE[08006] SSL connection has been closed unexpectedly', 'ssl_error'],
            'dns_error' => ['08006', 0, 'SQLSTATE[08006] could not translate host name "secret-host" to address', 'dns_error'],
            'too_many_connections' => ['53300', 0, 'SQLSTATE[53300] FATAL: too many connections for role "secret_user"', 'too_many_connections'],
            'database_missing' => ['3D000', 0, 'SQLSTATE[3D000] FATAL: database "secret_db" does not exist', 'database_missing'],
            'unknown' => ['XXXXX', 0, 'SQLSTATE[XXXXX] something unexpected happened', 'unknown'],
        ];
    }

    public function test_migrations_pending_is_null_when_count_unknown(): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
        $e->errorInfo = ['HY000', 2002, 'Connection refused'];

        DB::partialMock()->shouldReceive('connection')->andThrow($e);
        config(['services.health.admin_token' => 'test-admin-token', 'services.gemini.health_probe' => false]);

        $admin = $this->withHeader('X-Admin-Token', 'test-admin-token')->getJson('/api/health');
        $admin->assertStatus(503)
            ->assertJsonPath('info.migrations_pending', null)
            ->assertJsonPath('info.pending_migrations_count', -1);
    }

    public function test_database_down_marks_dependent_checks_skipped_and_public_still_degraded(): void
    {
        $e = new \PDOException('SQLSTATE[HY000] [2002] Connection refused', 2002);
        $e->errorInfo = ['HY000', 2002, 'Connection refused'];

        DB::partialMock()->shouldReceive('connection')->andThrow($e);
        config(['services.health.admin_token' => 'test-admin-token', 'services.gemini.health_probe' => false]);

        $public = $this->getJson('/api/health');
        $public->assertStatus(503)->assertExactJson(['status' => 'degraded']);

        $admin = $this->withHeader('X-Admin-Token', 'test-admin-token')->getJson('/api/health');
        $admin->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('failed_checks', ['database'])
            ->assertJsonPath('checks.database.ok', false)
            ->assertJsonPath('checks.database.reason', 'connection_refused')
            ->assertJsonPath('checks.schema.ok', null)
            ->assertJsonPath('checks.schema.skipped', 'database_down')
            ->assertJsonPath('checks.cache.ok', null)
            ->assertJsonPath('checks.cache.skipped', 'database_down')
            ->assertJsonPath('checks.queue.ok', null)
            ->assertJsonPath('checks.queue.skipped', 'database_down')
            ->assertJsonPath('info.failed_jobs.skipped', 'database_down');
    }
}
