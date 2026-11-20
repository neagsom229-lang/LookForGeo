<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class StuckAnalysisReaperTest extends TestCase
{
    use RefreshDatabase;

    private function analysisFor(User $user, array $overrides = []): GeoAnalysis
    {
        return GeoAnalysis::create(array_merge([
            'user_id' => $user->id,
            'status' => 'processing',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service',
            'progress' => 40,
            'image_path' => 'analyses/old.png',
            'started_at' => now()->subMinutes(20),
            'created_at' => now()->subMinutes(20),
        ], $overrides));
    }

    public function test_reaper_fails_only_stuck_processing_analyses(): void
    {
        $user = User::factory()->create();
        $stuck = $this->analysisFor($user);
        $fresh = $this->analysisFor($user, ['started_at' => now()]);
        $completed = $this->analysisFor($user, [
            'status' => 'completed',
            'started_at' => now()->subMinutes(30),
        ]);

        $this->artisan('analyses:reap-stuck', ['--minutes' => 10])
            ->expectsOutputToContain('failed 1 stuck analysis')
            ->assertExitCode(0);

        $stuck->refresh();
        $fresh->refresh();
        $completed->refresh();

        $this->assertSame('failed', $stuck->status);
        $this->assertSame('Timed out', $stuck->stage_label);
        $this->assertStringContainsString('timed out', $stuck->error);
        $this->assertNotNull($stuck->finished_at);

        $this->assertSame('processing', $fresh->status);
        $this->assertSame('completed', $completed->status);
    }

    public function test_status_reaps_a_stuck_analysis_on_read(): void
    {
        $user = User::factory()->create();
        $analysis = $this->analysisFor($user, [
            'status' => 'pending',
            'started_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user, 'web')
            ->getJson("/api/analyze/{$analysis->id}/status")
            ->assertOk()
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('reaped', true)
            ->assertJsonPath('error', 'This analysis waited too long for a worker and was stopped. Use Retry analysis to try again.');

        $this->assertSame('failed', $analysis->fresh()->status);
    }

    public function test_dry_run_does_not_change_rows(): void
    {
        $user = User::factory()->create();
        $stuck = $this->analysisFor($user);

        $this->artisan('analyses:reap-stuck', ['--minutes' => 10, '--dry-run' => true])
            ->expectsOutputToContain('Would fail 1 stuck analysis')
            ->assertExitCode(0);

        $this->assertSame('processing', $stuck->fresh()->status);
    }

    public function test_requeue_option_dispatches_a_new_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $stuck = $this->analysisFor($user);

        $this->artisan('analyses:reap-stuck', ['--minutes' => 10, '--requeue' => true])
            ->expectsOutputToContain('re-queued 1 stuck analysis')
            ->assertExitCode(0);

        $this->assertSame('pending', $stuck->fresh()->status);
        Queue::assertPushed(AnalyzeImageJob::class);
    }
}
