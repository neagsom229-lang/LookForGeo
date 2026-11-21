<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Jobs\UploadAnalysisImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CloudinaryFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function makeAnalysis(User $user): GeoAnalysis
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/local.png', 'image-bytes');
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Queued',
            'progress' => 10,
            'image_path' => 'analyses/local.png',
            'image_url' => '/storage/analyses/local.png',
        ]);

        return $analysis;
    }

    public function test_completion_precedes_the_cloudinary_follow_up(): void
    {
        Queue::fake();
        config(['services.gemini.retries' => 0, 'services.gemini.max_total_time' => 20, 'services.gemini.min_attempt_seconds' => 5]);
        Http::fake(['*' => Http::response([
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
        ], 200)]);

        $analysis = $this->makeAnalysis(User::factory()->create());
        (new AnalyzeImageJob($analysis->id, null, 'local.png'))->handle(app(GeminiService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('/storage/analyses/local.png', $analysis->image_url);
        Queue::assertPushed(UploadAnalysisImageJob::class);
    }

    public function test_follow_up_upload_failure_keeps_the_analysis_completed(): void
    {
        config([
            'services.cloudinary.cloud_name' => null,
            'services.cloudinary.api_key' => null,
            'services.cloudinary.api_secret' => null,
        ]);
        $analysis = $this->makeAnalysis(User::factory()->create());
        $analysis->update(['status' => 'completed']);

        (new UploadAnalysisImageJob($analysis->id))->handle();

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame('/storage/analyses/local.png', $analysis->image_url);
    }

    public function test_local_file_is_read_before_any_http_self_fetch(): void
    {
        config(['services.gemini.retries' => 0, 'services.gemini.max_total_time' => 20, 'services.gemini.min_attempt_seconds' => 5]);
        $selfFetches = 0;
        Http::fake(function ($request) use (&$selfFetches) {
            if (! str_contains($request->url(), 'generateContent')) {
                $selfFetches++;

                return Http::response(['error' => 'self fetch'], 500);
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

        $analysis = $this->makeAnalysis(User::factory()->create());
        (new AnalyzeImageJob($analysis->id, null, 'local.png'))->handle(app(GeminiService::class));

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame(0, $selfFetches, 'no HTTP self-fetch when a local file exists');
    }
}
