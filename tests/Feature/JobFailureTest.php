<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class JobFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A job that throws must ALWAYS leave the analysis as failed with a
     * user-readable message (the testing queue connection is sync, so the
     * job body runs inline -- including its internal fail() throw).
     */
    public function test_worker_exception_marks_analysis_failed_with_readable_message(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'processing',
            'stage' => 0,
            'stage_label' => 'Input',
            'progress' => 10,
            'image_path' => 'uploads/analyses/does-not-exist.png',
            'started_at' => now(),
        ]);

        AnalyzeImageJob::dispatch($analysis->id, null, 'missing.png');

        $analysis->refresh();

        $this->assertSame('failed', $analysis->status);
        $this->assertNotNull($analysis->error);
        $this->assertStringContainsString('No image found', $analysis->error);
        $this->assertNotNull($analysis->finished_at);
    }
}
