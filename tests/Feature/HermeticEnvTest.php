<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * phpunit.xml uses force="true" so these values must win even when a .env
 * file in the checkout says otherwise (see composer test:decoy).
 */
class HermeticEnvTest extends TestCase
{
    public function test_phpunit_env_overrides_any_decoy_env(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('testing', config('logging.default'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('gemini-3.5-flash-lite', config('services.gemini.model'));
        $this->assertSame('gemini-3.1-flash-lite', config('services.gemini.fallback_model'));
        $this->assertSame('', config('services.gemini.escalation_model'));
        $this->assertSame('', config('services.cloudinary.cloud_name'));
    }
}
