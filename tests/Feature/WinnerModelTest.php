<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class WinnerModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_analysis_and_stats_show_the_fallback_that_answered(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/local.png', 'image-bytes');
        config([
            'services.gemini.model' => 'primary-model',
            'services.gemini.fallback_model' => 'fallback-model',
            'services.gemini.retries' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 5,
            'services.gemini.hedge_after' => 0,
        ]);

        // Primary returns 503 for every attempt; the fallback answers.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'primary-model')) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'confidence' => 80,
                            'reasoning' => 'Tower',
                            'description' => 'Tower',
                        ]),
                    ]]],
                ]],
            ], 200);
        });

        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Queued',
            'progress' => 10,
            'image_path' => 'analyses/local.png',
            'image_url' => '/storage/analyses/local.png',
        ]);

        (new AnalyzeImageJob($analysis->id, null, 'local.png'))->handle(app(GeminiService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        // Stored model is the model that actually produced the answer.
        $this->assertSame('fallback-model', $analysis->model_used);

        $output = new BufferedOutput;
        Artisan::call('analyses:last', [], $output);
        $text = $output->fetch();
        $this->assertStringContainsString('model (winner)', $text);
        $this->assertStringContainsString('fallback-model', $text);
        $this->assertStringNotContainsString('primary-model', $text);

        // analyses:stats groups by the same stored (winning) model.
        $statsOutput = new BufferedOutput;
        Artisan::call('analyses:stats', [], $statsOutput);
        $statsText = $statsOutput->fetch();
        $this->assertStringContainsString('fallback-model', $statsText);
        $this->assertStringNotContainsString('primary-model', $statsText);
    }
}
