<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AnalysisRetryTest extends TestCase
{
    use RefreshDatabase;

    private function failedAnalysisFor(User $user): GeoAnalysis
    {
        return GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'failed',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service, retry 2',
            'progress' => 40,
            'image_path' => 'analyses/x.png',
            'error' => 'The AI service did not respond. Please retry.',
            'finished_at' => now(),
        ]);
    }

    public function test_owner_can_retry_a_failed_analysis(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $analysis = $this->failedAnalysisFor($user);

        $this->actingAs($user, 'web')
            ->postJson("/api/analyze/{$analysis->id}/retry")
            ->assertOk()
            ->assertJsonPath('success', true);

        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame('Retrying', $analysis->stage_label);
        $this->assertNull($analysis->error);
        $this->assertNull($analysis->finished_at);
        Queue::assertPushed(AnalyzeImageJob::class);
    }

    public function test_cross_user_cannot_retry(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $analysis = $this->failedAnalysisFor($owner);

        $this->actingAs($other, 'web')
            ->postJson("/api/analyze/{$analysis->id}/retry")
            ->assertStatus(404);

        $this->assertSame('failed', $analysis->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_non_failed_analysis_cannot_be_retried(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $analysis = $this->failedAnalysisFor($user);
        $analysis->update(['status' => 'completed']);

        $this->actingAs($user, 'web')
            ->postJson("/api/analyze/{$analysis->id}/retry")
            ->assertStatus(400);

        Queue::assertNothingPushed();
    }
}
