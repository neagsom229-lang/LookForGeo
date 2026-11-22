<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AnalysesStatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_excludes_failures_from_latency_and_reports_them_separately(): void
    {
        $user = User::factory()->create();
        config(['services.gemini.fallback_model' => 'gemini-3.1-flash-lite']);

        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'model_used' => 'gemini-3.5-flash-lite',
            'processing_ms' => 1000,
            'queue_ms' => 200,
            'gemini_ms' => 800,
            'total_ms' => 1000,
            'result' => ['landmark_name' => 'Eiffel Tower', 'hedged' => true, 'hedge_winner' => 'gemini-3.1-flash-lite', 'abandoned_primary' => 'gemini-3.5-flash-lite', 'primary_abandoned_ms' => 1000, 'prompt_tokens' => 1000, 'output_tokens' => 200, 'thought_tokens' => 55],
        ]);
        // A failure has no answering model: model_used stays null and its
        // processing_ms must not enter the per-model latency table.
        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'failed',
            'stage_label' => GeminiFailureClassifier::STAGE_LABELS['quota'],
            'failure_category' => 'quota',
            'model_used' => null,
            'processing_ms' => 3000,
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:stats', ['--hours' => 24], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('p50 processing', $text);
        $this->assertStringContainsString('total (end-to-end)', $text);
        $this->assertStringContainsString('gemini', $text);
        $this->assertStringContainsString('queue', $text);
        $this->assertStringContainsString('thought=55', $text);
        // Failures are reported in their own section only.
        $this->assertStringContainsString('Failed: 1', $text);
        $this->assertStringContainsString('failure_category', $text);
        $this->assertStringContainsString('quota', $text);
        $this->assertStringNotContainsString('| failed |', $text);
        $this->assertStringContainsString('hedge wins: 1', $text);
        $this->assertStringContainsString('Abandoned primaries: 1', $text);
        $this->assertStringContainsString('elapsed at abandonment', $text);
        $this->assertStringNotContainsString('AIza', $text);
    }
}
