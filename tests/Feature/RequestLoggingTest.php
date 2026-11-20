<?php

namespace Tests\Feature;

use App\Logging\NoEmojiProcessor;
use App\Logging\RequestIdProcessor;
use App\Logging\SecretLogProcessor;
use App\Support\RequestContext;
use DateTimeImmutable;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class RequestLoggingTest extends TestCase
{
    public function test_request_id_header_is_echoed(): void
    {
        $this->withHeader('X-Request-ID', 'trace-123')
            ->getJson('/api/health')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'trace-123');
    }

    public function test_request_id_is_generated_when_absent(): void
    {
        $response = $this->getJson('/api/health')->assertOk();
        $id = $response->headers->get('X-Request-ID');
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', (string) $id);
    }

    public function test_production_processors_strip_secrets_emoji_and_add_request_id(): void
    {
        app(RequestContext::class)->setId('req-42');

        $record = new LogRecord(
            new DateTimeImmutable,
            'production',
            Level::Error,
            '🚀 Gemini failed key=AIzaSyFakeSecret1234567890',
            ['detail' => '⚠️ retry'],
            ['tag' => '✅']
        );

        foreach ([new SecretLogProcessor, new RequestIdProcessor, new NoEmojiProcessor] as $processor) {
            $record = $processor($record);
        }

        $this->assertStringNotContainsString('AIza', $record->message);
        $this->assertStringNotContainsString('AIzaSyFakeSecret1234567890', json_encode($record->context));
        $this->assertStringNotContainsString('🚀', $record->message);
        $this->assertStringNotContainsString('⚠️', json_encode($record->context));
        $this->assertSame('req-42', $record->extra['request_id']);
    }

    public function test_json_formatter_is_configured_for_production(): void
    {
        config(['app.env' => 'production']);

        $this->assertSame(
            JsonFormatter::class,
            config('logging.channels.production.formatter')
        );
        $this->assertContains(
            RequestIdProcessor::class,
            config('logging.channels.production.processors')
        );
    }
}
