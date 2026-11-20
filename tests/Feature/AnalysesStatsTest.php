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

    public function test_stats_reports_model_latency_failures_and_hedge_rate(): void
    {
        $user = User::factory()->create();
        config(['services.gemini.fallback_model' => 'gemini-3.1-flash-lite']);

        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'model_used' => 'gemini-3.5-flash-lite',
            'processing_ms' => 1000,
            'result' => ['landmark_name' => 'Eiffel Tower', 'hedged' => true, 'hedge_winner' => 'gemini-3.1-flash-lite', 'abandoned_primary' => 'gemini-3.5-flash-lite', 'primary_abandoned_ms' => 1000],
        ]);
        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'failed',
            'stage_label' => GeminiFailureClassifier::STAGE_LABELS['quota'],
            'failure_category' => 'quota',
            'model_used' => 'gemini-3.1-flash-lite',
            'processing_ms' => 3000,
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:stats', ['--hours' => 24], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $text);
        $this->assertStringContainsString('gemini-3.1-flash-lite', $text);
        $this->assertStringContainsString('p50 processing', $text);
        $this->assertStringContainsString('quota', $text);
        $this->assertStringContainsString('hedge wins: 1', $text);
        $this->assertStringContainsString('Abandoned primaries: 1', $text);
        $this->assertStringContainsString('elapsed at abandonment', $text);
        $this->assertStringNotContainsString('AIza', $text);
    }
}
