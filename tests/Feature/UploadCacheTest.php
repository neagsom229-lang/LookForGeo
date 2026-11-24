<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
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
