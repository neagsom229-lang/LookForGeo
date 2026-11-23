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

    public function test_analyses_last_prints_the_last_five_with_created_at_and_status(): void
    {
        $user = User::factory()->create();
        for ($i = 1; $i <= 6; $i++) {
            GeoAnalysis::create([
                'user_id' => $user->id,
                'status' => $i === 6 ? 'failed' : 'completed',
                'stage_label' => $i === 6 ? GeminiFailureClassifier::STAGE_LABELS['quota'] : 'Done',
                'started_at' => now()->subSeconds(12),
                'finished_at' => now(),
                'queue_ms' => 200,
                'gemini_ms' => 800,
                'total_ms' => 1000,
                'result' => [
                    'landmark_name' => 'Eiffel Tower '.$i,
                    'confidence' => 42,
                    'low_confidence' => true,
                ],
            ]);
        }

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:last', [], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        foreach (['created_at', 'status', 'model (winner)', 'processing_seconds', 'landmark', 'confidence'] as $field) {
            $this->assertStringContainsString($field, $text);
        }
        // Only five rows, newest first: row 6 present, row 1 absent.
        $this->assertStringContainsString('Eiffel Tower 6', $text);
        $this->assertStringNotContainsString('Eiffel Tower 1', $text);
        $this->assertSame(4, substr_count($text, 'completed'));
        $this->assertStringContainsString('failed', $text);
        $this->assertMatchesRegularExpression('/\| 12\s+\|/', $text);
        $this->assertStringNotContainsString('| -12 |', $text);
        $this->assertStringNotContainsString('AIza', $text);
    }
}
