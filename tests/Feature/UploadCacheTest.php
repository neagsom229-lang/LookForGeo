<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
