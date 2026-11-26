<?php

namespace Tests\Feature;

use Tests\TestCase;

class GeoEvalTest extends TestCase
{
    public function test_mock_eval_produces_a_diffable_json_report(): void
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'geo-eval-'.uniqid('', true).'.json';

        try {
            $this->artisan('geo:eval', [
                '--limit' => 3,
                '--report' => $path,
            ])->assertExitCode(0);

            $this->assertFileExists($path);
            $report = json_decode((string) file_get_contents($path), true);

            $this->assertSame('mock', $report['mode']);
            $this->assertSame(3, $report['count']);
            $this->assertCount(3, $report['rows']);
            $this->assertArrayHasKey('median_error_km', $report['metrics']);
            $this->assertArrayHasKey('pct_within', $report['metrics']);
            $this->assertArrayHasKey('latency_p50_s', $report['metrics']);
            $this->assertArrayHasKey('calibration', $report['metrics']);
            $this->assertArrayHasKey('low:0-49', $report['metrics']['calibration']);
            $this->assertStringContainsString('CC0', $report['fixture_license']);
            $this->assertSame('Harness smoke test, NOT accuracy: mock mode returns the ground truth as the prediction.', $report['disclaimer']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_live_eval_refuses_without_a_key(): void
    {
        config(['services.gemini.key' => null]);

        $this->artisan('geo:eval', ['--live' => true])
            ->expectsOutputToContain('GEMINI_API_KEY')
            ->assertExitCode(1);
    }
}
