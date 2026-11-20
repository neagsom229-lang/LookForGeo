<?php

namespace Tests\Feature;

use Tests\TestCase;

class CspTest extends TestCase
{
    /**
     * CSP connect-src must follow APP_URL, never a hardcoded deploy host.
     */
    public function test_csp_connect_src_follows_app_url_local(): void
    {
        config()->set('app.url', 'http://localhost:8000');

        $csp = $this->getJson('/api/health')->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertStringContainsString("connect-src 'self' http://localhost:8000", $csp);
        $this->assertStringNotContainsString('lookforgeo.onrender.com', $csp);
    }

    public function test_csp_connect_src_follows_app_url_prod_shaped(): void
    {
        config()->set('app.url', 'https://study.onrender.com');

        $csp = $this->getJson('/api/health')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' https://study.onrender.com", $csp);
        $this->assertStringNotContainsString('lookforgeo.onrender.com', $csp);
    }
}
