<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Jobs\UploadAnalysisImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisSyncQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatching_analysis_job_with_sync_queue_moves_record_to_completed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/test.jpg', $this->tinyJpeg());

        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/test.jpg',
            'image_url' => Storage::disk('public')->url('analyses/test.jpg'),
            'started_at' => now(),
        ]);

        config([
            'queue.default' => 'sync',
            'services.gemini.fallback_model' => null,
            'services.gemini.hedge_after' => 0,
            'services.gemini.escalation_model' => '',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'landmark_name' => 'Test Landmark',
                                'city' => 'Test City',
                                'country' => 'Test Country',
                                'latitude' => 10.0,
                                'longitude' => 20.0,
                                'confidence' => 90,
                                'reasoning' => 'Clear visual match.',
                                'description' => 'A test location.',
                                'tags' => ['test'],
                            ]),
                        ]],
                    ],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
            ], 200),
        ]);

        Bus::fake([UploadAnalysisImageJob::class]);

        AnalyzeImageJob::dispatchSync($analysis->id, Storage::disk('public')->path('analyses/test.jpg'), 'test.jpg');

        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);
        $this->assertSame(100, $analysis->progress);
        $this->assertNotNull($analysis->result);
    }

    private function tinyJpeg(): string
    {
        $img = imagecreatetruecolor(8, 8);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefill($img, 0, 0, $white);
        ob_start();
        if (function_exists('imagepng')) {
            imagepng($img);
        } else {
            imagejpeg($img, null, 80);
        }
        $data = ob_get_clean();
        imagedestroy($img);

        return (string) $data;
    }
}
