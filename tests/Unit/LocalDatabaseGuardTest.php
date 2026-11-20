<?php

namespace Tests\Unit;

use App\Support\LocalDatabaseGuard;
use PHPUnit\Framework\TestCase;

class LocalDatabaseGuardTest extends TestCase
{
    public function test_pgsql_driver_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalDatabaseGuard::assert('pgsql', ['host' => 'localhost']);
    }

    public function test_sqlite_connection_with_production_url_is_refused(): void
    {
        // The real-world failure mode: sqlite default + a pasted production
        // DATABASE_URL (Laravel parses the URL and the connection silently
        // points at the prod host).
        $this->expectException(\RuntimeException::class);

        LocalDatabaseGuard::assert('sqlite', [
            'url' => 'postgres://db_user:secret@dpg-abc123.oregon-postgres.render.com/prod_db',
        ]);
    }

    public function test_host_containing_render_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        LocalDatabaseGuard::assert('sqlite', [
            'host' => 'dpg-abc123.oregon-postgres.render.com',
        ]);
    }

    public function test_local_sqlite_is_allowed(): void
    {
        LocalDatabaseGuard::assert('sqlite', [
            'url' => 'sqlite:///:memory:',
            'database' => ':memory:',
        ]);
        LocalDatabaseGuard::assert('sqlite', ['database' => '/tmp/local.sqlite']);

        $this->assertTrue(true);
    }
}
