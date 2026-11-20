<?php

namespace Tests\Unit;

use App\Services\Gemini\GeminiClient;
use PHPUnit\Framework\TestCase;

class GeminiBaseUrlTest extends TestCase
{
    public function test_local_honors_any_override(): void
    {
        $this->assertSame(
            'http://127.0.0.1:54321/v1beta',
            GeminiClient::resolveBaseUrl('http://127.0.0.1:54321/v1beta', 'local')
        );
    }

    public function test_testing_honors_any_override(): void
    {
        $this->assertSame(
            'http://fake.example/v1beta',
            GeminiClient::resolveBaseUrl('http://fake.example/v1beta/', 'testing')
        );
    }

    public function test_production_refuses_cleartext_override(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GEMINI_BASE_URL must be https');

        GeminiClient::resolveBaseUrl('http://evil.example/v1beta', 'production');
    }

    public function test_production_accepts_https_override(): void
    {
        $this->assertSame(
            'https://proxy.example/v1beta',
            GeminiClient::resolveBaseUrl('https://proxy.example/v1beta', 'production')
        );
    }

    public function test_default_endpoint_used_without_override(): void
    {
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta',
            GeminiClient::resolveBaseUrl(null, 'production')
        );
        $this->assertSame(
            'https://generativelanguage.googleapis.com/v1beta',
            GeminiClient::resolveBaseUrl('', 'local')
        );
    }
}
