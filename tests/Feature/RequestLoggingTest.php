<?php

namespace Tests\Feature;

use App\Logging\NoEmojiProcessor;
use App\Logging\RequestIdProcessor;
use App\Logging\SecretLogProcessor;
use App\Support\RequestContext;
use DateTimeImmutable;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
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

    public function test_log_channel_processors_and_json_formatter_handle_hostile_input(): void
    {
        app(RequestContext::class)->setId('req-log-1');

        $stream = fopen('php://memory', 'w+');
        $handler = new StreamHandler($stream);
        $handler->setFormatter(new JsonFormatter);

        $logger = new Logger('test');
        $logger->pushProcessor(new SecretLogProcessor);
        $logger->pushProcessor(new RequestIdProcessor);
        $logger->pushProcessor(new NoEmojiProcessor);
        $logger->pushHandler($handler);

        $message = "hello 😀 bad\x80\x90\xa1 key=AIzaSyFakeSecret1234567890 ".str_repeat('A', 1024 * 1024);
        $thrown = null;

        try {
            $logger->info($message);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNull($thrown, $thrown ? $thrown->getMessage() : '');

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        $lines = array_values(array_filter(explode("\n", (string) $output), fn ($line) => $line !== ''));
        $this->assertCount(1, $lines);

        $json = json_decode($lines[0], true);
        $this->assertIsArray($json);
        $this->assertSame('req-log-1', $json['extra']['request_id'] ?? null);
        $this->assertStringNotContainsString('AIzaSyFakeSecret1234567890', $lines[0]);
        $this->assertStringContainsString('hello', (string) ($json['message'] ?? ''));
    }
}
