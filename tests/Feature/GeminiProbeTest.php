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
        $this->assertStringContainsString('gemini-3.5-flash-lite', $text);
        $this->assertStringContainsString('gemini-3.1-flash-lite', $text);
        $this->assertStringContainsString('HTTP 200', $text);
        $this->assertMatchesRegularExpression('/\d+\.\d+s/', $text);
        $this->assertStringNotContainsString($key, $text);
        $this->assertStringNotContainsString('key=', $text);
    }

    public function test_probe_sends_the_real_analysis_payload_shape(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake([
            '*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ], 200),
        ]);

        Artisan::call('gemini:probe', [], new BufferedOutput);

        Http::assertSent(function ($request) {
            $data = $request->data();
            $inline = $data['contents'][0]['parts'][1]['inline_data'] ?? [];

            return isset($inline['data'])
                && ($inline['mime_type'] ?? null) === 'image/png'
                && isset($data['generationConfig']['responseSchema'])
                && ($data['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'low';
        });
    }

    public function test_probe_models_flag_prints_sanitized_error_and_quota_details(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'model-a')) {
                return Http::response([
                    'error' => [
                        'message' => 'quota exceeded AIzaSyFakeProbeKey1234567890',
                        'details' => [[
                            'quotaMetric' => 'generativelanguage.googleapis.com/generate_content_requests',
                            'quotaId' => 'Q-123',
                        ]],
                    ],
                ], 429);
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ], 200);
        });

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', ['--models' => 'model-a,model-b'], $output);
        $text = $output->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('model-a', $text);
        $this->assertStringContainsString('model-b', $text);
        $this->assertStringContainsString('quotaMetric', $text);
        $this->assertStringContainsString('Q-123', $text);
        $this->assertStringNotContainsString('AIzaSyFakeProbeKey1234567890', $text);
    }

    public function test_probe_repeat_prints_min_median_max(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
        ], 200)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', ['--models' => 'model-a', '--repeat' => 3], $output);
        $line = trim($output->fetch());

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/\d+\.\d{2}\/\d+\.\d{2}\/\d+\.\d{2}s/', $line);
        $this->assertStringContainsString('| ok', $line);
        Http::assertSentCount(3);
    }

    public function test_probe_thinking_override_is_sent(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
        ], 200)]);

        Artisan::call('gemini:probe', [
            '--models' => 'model-a',
            '--thinking' => 'high',
        ], new BufferedOutput);

        Http::assertSent(fn ($request) => ($request->data()['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'high');
    }

    public function test_probe_minimal_override_requires_recorded_live_200(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response([], 200)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', [
            '--models' => 'unverified-model',
            '--thinking' => 'minimal',
        ], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('no live 200 recorded', $output->fetch());
        Http::assertNothingSent();
    }

    public function test_probe_prints_one_fresh_line_per_model(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'model-a')) {
                return Http::response([
                    'error' => [
                        'message' => 'STALE-DETAIL-ONLY-FOR-A',
                        'details' => [['quotaId' => 'Q-A']],
                    ],
                ], 429);
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '{"ok":true}']]]]],
            ], 200);
        });

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', ['--models' => 'model-a,model-b'], $output);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output->fetch()))));

        $this->assertSame(1, $exit);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('quota', $lines[0]);
        $this->assertStringContainsString('STALE-DETAIL-ONLY-FOR-A', $lines[0]);
        $this->assertStringContainsString('model-b', $lines[1]);
        $this->assertStringContainsString('ok', $lines[1]);
        $this->assertStringNotContainsString('STALE-DETAIL-ONLY-FOR-A', $lines[1]);
        $this->assertStringNotContainsString('Q-A', $lines[1]);
    }

    public function test_gemini_models_lists_only_usable_flash_models(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response([
            'models' => [
                ['name' => 'models/gemini-3.5-flash-lite', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3-pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/gemini-3.1-flash-lite', 'supportedGenerationMethods' => ['embedContent']],
            ],
        ], 200)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:models', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $text);
        $this->assertStringNotContainsString('gemini-3-pro', $text);
        $this->assertStringNotContainsString('gemini-3.1-flash-lite', $text);
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
        $this->assertStringContainsString('overload', $text);
        $this->assertStringNotContainsString($key, $text);
    }
}
