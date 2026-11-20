<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AnalysesLastTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyses_last_prints_the_summary_without_secrets(): void
    {
        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'failed',
            'stage_label' => GeminiFailureClassifier::STAGE_LABELS['quota'],
            'started_at' => now()->subSeconds(12),
            'finished_at' => now(),
            'result' => [
                'landmark_name' => 'Eiffel Tower',
                'confidence' => 42,
                'low_confidence' => true,
            ],
        ]);
        $analysis->update(['model_used' => 'gemini-3.5-flash-lite']);

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:last', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        foreach (['id', 'status', 'model (winner)', 'processing_seconds', 'landmark', 'confidence', 'low_confidence', 'failure_category'] as $field) {
            $this->assertStringContainsString($field, $text);
        }
        $this->assertStringContainsString((string) $analysis->id, $text);
        $this->assertStringContainsString('gemini-3.5-flash-lite', $text);
        $this->assertStringContainsString('Eiffel Tower', $text);
        $this->assertStringContainsString('quota', $text);
        // Carbon 3 diffs are signed: finished_at after started_at must be +12.
        $this->assertStringContainsString('processing_seconds', $text);
        $this->assertStringContainsString('queue_ms', $text);
        $this->assertStringContainsString('gemini_ms', $text);
        $this->assertStringContainsString('total_ms (end-to-end)', $text);
        $this->assertStringNotContainsString('|-12|', $text);
        $this->assertStringNotContainsString('| -12 |', $text);
        $this->assertMatchesRegularExpression('/processing_seconds\s*\|\s*12(\.0+)?\s*\|/', $text);
        $this->assertStringNotContainsString('AIza', $text);
    }
}
