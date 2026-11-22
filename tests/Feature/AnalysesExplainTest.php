<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AnalysesExplainTest extends TestCase
{
    use RefreshDatabase;

    public function test_explains_a_zero_score_from_stored_signals_without_secrets(): void
    {
        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'completed',
            'result' => [
                'landmark_name' => 'Statue of Liberty',
                'confidence' => 0,
                'confidence_level' => 'Very Low',
                'low_confidence' => true,
                'confidence_basis' => 'calibrated-signals',
                'confidence_signals' => [
                    'exif_gps' => ['points' => 0, 'state' => 'unavailable'],
                    'country_consistency' => [
                        'points' => -20,
                        'state' => 'mismatch',
                        'method' => 'iso',
                        'stated' => 'United States',
                        'stated_code' => 'us',
                        'reverse_geocoded' => 'Canada',
                        'reverse_code' => 'ca',
                    ],
                    'visual_cues' => ['points' => 10, 'count' => 4],
                    'resolution' => ['points' => -10, 'state' => 'low', 'short_edge_px' => 320],
                    'candidate_gap' => ['points' => 15, 'gap' => 60],
                    'coordinate_sources' => ['points' => 0, 'count' => 1],
                ],
                'confidence_factors' => [
                    'No EXIF GPS to cross-check the model coordinates.',
                    'Country mismatch: model says United States, reverse geocode says Canada.',
                    'Low-resolution input (320 px short edge) reduces reliability.',
                ],
            ],
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:explain', ['id' => $analysis->id], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('confidence=0', $text);
        $this->assertStringContainsString('country_consistency', $text);
        $this->assertStringContainsString('mismatch', $text);
        $this->assertStringContainsString('Sum of signal points: -5', $text);
        $this->assertStringContainsString('Country mismatch:', $text);
        $this->assertStringContainsString('Score is 0', $text);
        $this->assertStringNotContainsString('AIza', $text);

        if (getenv('TEST_VERBOSE') === '1') {
            fwrite(STDERR, "\n=== analyses:explain {$analysis->id} (score 0) ===\n".$text."\n");
        }
    }

    public function test_missing_analysis_fails_cleanly(): void
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:explain', ['id' => 999999], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output->fetch());
    }
}
