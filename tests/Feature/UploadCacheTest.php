<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase D item 1: identical bytes from the same user return the existing
 * completed analysis instantly, with zero additional Gemini calls.
 */
class UploadCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini.forward_geocode_missing' => false]);
        Storage::fake('public');
        Storage::disk('public')->makeDirectory('analyses');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'confidence' => 90,
                            'reasoning' => 'Iron lattice tower',
                            'description' => 'The Eiffel Tower',
                            'vantage_point' => 'ground',
                            'visual_clues' => ['tower'],
                            'tags' => ['landmark'],
                            'candidates' => [],
                        ]),
                    ]]],
                ]],
            ], 200),
        ]);
    }

    private function upload(User $user, string $bytes)
    {
        return $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('photo.png', $bytes),
        ]);
    }

    public function test_repeat_upload_by_same_user_is_cached_with_no_gemini_call(): void
    {
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $first = $this->upload($user, $bytes);
        $first->assertOk()->assertJsonPath('success', true);
        $id = $first->json('id');
        $this->assertSame('completed', GeoAnalysis::find($id)->status);
        Http::assertSentCount(1);

        $second = $this->upload($user, $bytes);
        $second->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $id)
            ->assertJsonPath('data.status', 'completed');

        // Still exactly one Gemini call: the repeat cost nothing.
        Http::assertSentCount(1);
    }

    public function test_same_bytes_for_different_user_do_not_share_cache(): void
    {
        $firstUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $first = $this->upload($firstUser, $bytes);
        $second = $this->upload($otherUser, $bytes);

        $second->assertOk();
        $this->assertNotSame($first->json('id'), $second->json('id'));
        $this->assertFalse((bool) $second->json('cached'));
        Http::assertSentCount(2);
    }

    /**
     * A second upload while the first analysis is still queued/processing must
     * reuse the running row: no second job, no second Gemini cost, same id.
     */
    public function test_second_upload_while_running_reuses_the_analysis_and_dispatches_nothing(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $first = $this->upload($user, $bytes);
        $first->assertOk()->assertJsonPath('success', true);
        $id = $first->json('id');
        $this->assertNotSame('completed', GeoAnalysis::find($id)->status);
        Queue::assertPushed(AnalyzeImageJob::class, 1);

        $second = $this->upload($user, $bytes);
        $second->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $id);

        Queue::assertPushed(AnalyzeImageJob::class, 1);
        $this->assertSame(1, GeoAnalysis::where('image_hash', hash('sha256', $bytes))->count());
    }

    /** The guard must also match a row the worker already flipped to processing. */
    public function test_processing_analysis_is_reused(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'processing',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service',
            'progress' => 40,
            'image_path' => 'analyses/running.jpg',
            'image_url' => '/storage/analyses/running.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now(),
        ]);

        $this->upload($user, $bytes)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $running->id)
            ->assertJsonPath('data.status', 'processing');

        Queue::assertNothingPushed();
        $this->assertSame(1, GeoAnalysis::count());
    }

    /** Identical bytes from another user must never attach to their run. */
    public function test_running_analysis_of_another_user_is_not_reused(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $owner->id,
            'status' => 'processing',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service',
            'progress' => 40,
            'image_path' => 'analyses/running.jpg',
            'image_url' => '/storage/analyses/running.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now(),
        ]);

        $response = $this->upload($other, $bytes);

        $response->assertOk();
        $this->assertNotSame($running->id, $response->json('id'));
        $this->assertFalse((bool) $response->json('cached'));
        Queue::assertPushed(AnalyzeImageJob::class, 1);
    }

    /** A failed run is terminal: the user must get a fresh analysis. */
    public function test_failed_analysis_is_not_reused(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'failed',
            'stage' => 2,
            'stage_label' => 'Failed',
            'progress' => 40,
            'image_path' => 'analyses/failed.jpg',
            'image_url' => '/storage/analyses/failed.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now(),
            'finished_at' => now(),
            'error' => 'boom',
        ]);

        $response = $this->upload($user, $bytes);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('cached'));
        $this->assertSame('pending', GeoAnalysis::find($response->json('id'))->status);
        Queue::assertPushed(AnalyzeImageJob::class, 1);
        $this->assertSame(2, GeoAnalysis::count());
    }

    /** A reused RUNNING analysis must not look like a completed cached result. */
    public function test_running_guard_response_is_marked_running_with_no_result(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'processing',
            'stage' => 2,
            'stage_label' => 'Waiting for AI service',
            'progress' => 40,
            'image_path' => 'analyses/running.jpg',
            'image_url' => '/storage/analyses/running.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now(),
        ]);

        $this->upload($user, $bytes)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('cached', true)
            ->assertJsonPath('running', true)
            ->assertJsonPath('data.running', true)
            ->assertJsonPath('data.id', $running->id)
            ->assertJsonMissingPath('data.result');
    }

    /** The duplicate-upload guard must serialize check + create with a lock. */
    public function test_upload_lock_rejects_a_second_upload_while_held(): void
    {
        config(['services.analysis.upload_lock_wait_seconds' => 0]);
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();
        $key = 'analysis:upload:'.$user->id.':'.hash('sha256', $bytes);

        $lock = Cache::lock($key, 10);
        $this->assertTrue($lock->get());

        $this->upload($user, $bytes)
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(0, GeoAnalysis::count());
        $lock->release();
    }

    /** Two sequential calls: one row, one job, and the lock is released. */
    public function test_two_sequential_uploads_release_the_lock_and_create_one_analysis(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();
        $key = 'analysis:upload:'.$user->id.':'.hash('sha256', $bytes);

        $first = $this->upload($user, $bytes);
        $second = $this->upload($user, $bytes);

        $first->assertOk();
        $second->assertOk();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, GeoAnalysis::count());
        Queue::assertPushed(AnalyzeImageJob::class, 1);
        $lock = Cache::lock($key, 10);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    /** A pending row nobody picked up for >3 min is stale, not reusable. */
    public function test_stale_pending_upload_is_marked_failed_and_a_new_analysis_created(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $stale = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/stale.jpg',
            'image_url' => '/storage/analyses/stale.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now()->subMinutes(25),
        ]);
        // timestamps are not fillable; force them so the row is genuinely stale.
        $stale->forceFill([
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(21),
        ])->save();

        $response = $this->upload($user, $bytes);

        $response->assertOk();
        $this->assertNotSame($stale->id, $response->json('id'));
        $this->assertSame('failed', $stale->fresh()->status);
        $this->assertNotNull($stale->fresh()->finished_at);
        $this->assertSame('pending', GeoAnalysis::find($response->json('id'))->status);
        Queue::assertPushed(AnalyzeImageJob::class, 1);
        $this->assertSame(2, GeoAnalysis::count());
    }

    /**
     * Staleness must follow updated_at (last worker touch), not created_at:
     * a job released at 30/90/180s is still legitimately in flight long after
     * its upload time and must never be retired by the guard.
     */
    public function test_pending_with_recent_updated_at_is_reused_even_when_created_long_ago(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/old-created.jpg',
            'image_url' => '/storage/analyses/old-created.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now()->subMinutes(25),
        ]);
        $running->forceFill([
            'created_at' => now()->subMinutes(25),
            'updated_at' => now()->subMinutes(5),
        ])->save();

        $this->upload($user, $bytes)
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $running->id);

        Queue::assertNothingPushed();
        $this->assertSame('pending', $running->fresh()->status);
        $this->assertSame(1, GeoAnalysis::count());
    }

    /** 18 minutes of waiting (inside the 20-minute budget) is still in flight. */
    public function test_pending_within_wait_budget_by_updated_at_is_reused(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/waiting.jpg',
            'image_url' => '/storage/analyses/waiting.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now()->subMinutes(18),
        ]);
        $running->forceFill([
            'created_at' => now()->subMinutes(18),
            'updated_at' => now()->subMinutes(18),
        ])->save();

        $this->upload($user, $bytes)
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $running->id);

        Queue::assertNothingPushed();
        $this->assertSame('pending', $running->fresh()->status);
    }

    /** A pending row still within the 3-minute window is reused normally. */
    public function test_fresh_pending_upload_is_reused(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $running = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Uploaded',
            'progress' => 10,
            'image_path' => 'analyses/fresh.jpg',
            'image_url' => '/storage/analyses/fresh.jpg',
            'image_hash' => hash('sha256', $bytes),
            'started_at' => now()->subMinutes(2),
        ]);
        $running->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $this->upload($user, $bytes)
            ->assertOk()
            ->assertJsonPath('cached', true)
            ->assertJsonPath('id', $running->id);

        Queue::assertNothingPushed();
    }

    public function test_cached_row_stores_the_sha256_hash(): void
    {
        $user = User::factory()->create();
        $bytes = $this->tinyPngBytes();

        $response = $this->upload($user, $bytes);

        $analysis = GeoAnalysis::find($response->json('id'));
        $this->assertSame(hash('sha256', $bytes), $analysis->image_hash);
    }

    private function tinyPngBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
