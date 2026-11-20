<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class GeminiProbeTest extends TestCase
{
    public function test_probe_prints_status_and_seconds_but_never_the_key(): void
    {
        $key = 'AIzaSyFakeProbeKey1234567890';
        config(['services.gemini.key' => $key]);

        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => '{"ok":true}']]],
                ]],
            ], 200),
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('gemini-3.6-flash', $text);
        $this->assertStringContainsString('gemini-3.5-flash', $text);
        $this->assertStringContainsString('HTTP 200', $text);
        $this->assertMatchesRegularExpression('/\d+\.\d+s/', $text);
        $this->assertStringNotContainsString($key, $text);
        $this->assertStringNotContainsString('key=', $text);
    }

    public function test_probe_reports_failure_without_key(): void
    {
        $key = 'AIzaSyFakeProbeKey1234567890';
        config(['services.gemini.key' => $key]);

        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', [], $output);
        $text = $output->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('HTTP 503', $text);
        $this->assertStringContainsString('FAIL', $text);
        $this->assertStringNotContainsString($key, $text);
    }
}
