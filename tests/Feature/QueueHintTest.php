<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QueueHintTest extends TestCase
{
    use RefreshDatabase;

    private function analysisFor(User $user, array $overrides = []): GeoAnalysis
    {
        return GeoAnalysis::create(array_merge([
            'user_id' => $user->id,
            'status' => 'processing',
            'stage' => 0,
            'stage_label' => 'Input',
            'progress' => 5,
            'started_at' => now(),
            'created_at' => now(),
        ], $overrides));
    }

    public function test_pending_over_20s_reports_queued_seconds_and_local_hint(): void
    {
        $user = User::factory()->create();
        $analysis = $this->analysisFor($user, [
            'status' => 'pending',
            'started_at' => now()->subMinutes(2),
            'created_at' => now()->subMinutes(2),
        ]);

        $response = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");

        $response->assertOk();
        $this->assertSame('pending', $response->json('status'));
        $this->assertGreaterThanOrEqual(119, (int) $response->json('queued_seconds'));
        $this->assertStringContainsString('queue worker not running? run composer dev', (string) $response->json('hint'));
    }

    public function test_stuck_processing_at_stage_zero_is_detected_as_queued(): void
    {
        $user = User::factory()->create();
        $analysis = $this->analysisFor($user, [
            'status' => 'processing',
            'stage' => 0,
            'progress' => 5,
            'started_at' => now()->subMinutes(3),
            'created_at' => now()->subMinutes(3),
        ]);

        $response = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");

        $response->assertOk();
        $this->assertArrayHasKey('queued_seconds', $response->json());
        $this->assertNotNull($response->json('hint'));
    }

    public function test_fresh_analysis_has_no_queue_warning(): void
    {
        $user = User::factory()->create();
        $analysis = $this->analysisFor($user);

        $response = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");

        $response->assertOk();
        $this->assertArrayNotHasKey('queued_seconds', $response->json());
        $this->assertArrayNotHasKey('hint', $response->json());
    }

    /**
     * With a real (non-sync) queue, an upload must sit at status=pending
     * until a worker picks it up -- and once it has waited > 20s, the
     * status endpoint must report queued_seconds + the local hint.
     */
    public function test_upload_stays_pending_until_worker_runs_and_then_hints(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));

        try {
            config(['queue.default' => 'database']);
            Storage::fake('public');

            $user = User::factory()->create();
            $response = $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
                'image' => UploadedFile::fake()->image('queued.png'),
            ]);

            $response->assertOk();
            $this->assertSame('pending', $response->json('data.status'));

            $analysis = GeoAnalysis::find($response->json('id'));
            $this->assertSame('pending', $analysis->status);

            // Simulate > 20s of queue wait without sleeping. Keep under the
            // 180s "delayed" threshold (AnalysisController) to avoid boundary flake.
            $analysis->forceFill([
                'created_at' => now()->subSeconds(179),
                'started_at' => now()->subSeconds(179),
            ])->save();

            $status = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");
            $status->assertOk();
            $this->assertSame('pending', $status->json('status'));
            $this->assertGreaterThanOrEqual(20, (int) $status->json('queued_seconds'));
            $this->assertStringContainsString('queue worker not running', (string) $status->json('hint'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
