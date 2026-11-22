<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class GeminiProbeTest extends TestCase
{
    private function body(string $name = 'X'): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode(['landmark_name' => $name]),
                ]]],
            ]],
        ];
    }

    public function test_probe_prints_table_with_status_category_and_no_key(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response($this->body(), 200)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $text);
        $this->assertStringContainsString('gemini-3.1-flash-lite', $text);
        $this->assertStringContainsString('category', $text);
        $this->assertStringContainsString('thinking level', $text);
        $this->assertMatchesRegularExpression('/\d+\.\d{2}\/\d+\.\d{2}\/\d+\.\d{2}/', $text);
        $this->assertStringNotContainsString('AIzaSyFakeProbeKey1234567890', $text);
        $this->assertStringNotContainsString('key=', $text);
    }

    public function test_probe_models_flag_prints_sanitized_error_and_quota_details(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'model-a')) {
                return Http::response([
                    'error' => [
                        'message' => 'quota exceeded AIzaSyFakeProbeKey1234567890',
                        'details' => [['quotaMetric' => 'requests', 'quotaId' => 'Q-123']],
                    ],
                ], 429);
            }

            return Http::response($this->body(), 200);
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
        Http::fake(['*' => Http::response($this->body(), 200)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', ['--models' => 'model-a', '--repeat' => 3], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/\d+\.\d{2}\/\d+\.\d{2}\/\d+\.\d{2}/', $text);
        Http::assertSentCount(3);
    }

    public function test_probe_thinking_override_is_sent(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response($this->body(), 200)]);

        Artisan::call('gemini:probe', ['--models' => 'model-a', '--thinking' => 'high'], new BufferedOutput);

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

    public function test_probe_image_is_resized_like_the_app(): void
    {
        config([
            'services.gemini.key' => 'AIzaSyFakeProbeKey1234567890',
            // Small fixture + low threshold triggers the downscale path
            // without exhausting memory on constrained CI runners.
            'services.gemini.compress_threshold_bytes' => 1000,
            'services.gemini.image_encoder' => 'png',
        ]);
        $image = imagecreatetruecolor(1200, 900);
        ob_start();
        imagepng($image);
        $path = tempnam(sys_get_temp_dir(), 'tg-probe').'.png';
        file_put_contents($path, (string) ob_get_clean());

        Http::fake(['*' => Http::response($this->body(), 200)]);
        try {
            Artisan::call('gemini:probe', ['--models' => 'model-a', '--image' => $path], new BufferedOutput);
        } finally {
            @unlink($path);
        }

        Http::assertSent(function ($request) {
            $inline = $request->data()['contents'][0]['parts'][1]['inline_data'] ?? [];

            return ($inline['mime_type'] ?? null) === 'image/jpeg' || ($inline['mime_type'] ?? null) === 'image/png';
        });
    }

    public function test_probe_reports_failure_without_key(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeProbeKey1234567890']);
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $output = new BufferedOutput;
        $exit = Artisan::call('gemini:probe', [], $output);
        $text = $output->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('overload', $text);
        $this->assertStringNotContainsString('AIzaSyFakeProbeKey1234567890', $text);
    }
}
