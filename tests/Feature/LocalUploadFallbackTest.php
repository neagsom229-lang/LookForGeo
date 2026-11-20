<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocalUploadFallbackTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Local fallback path: with Cloudinary not configured, the upload goes
     * to the public disk and the whole pipeline still completes — no network.
     */
    public function test_upload_uses_local_disk_when_cloudinary_is_not_configured(): void
    {
        Storage::fake('public');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'region' => 'Ile-de-France',
                            'latitude' => 48.8584,
                            'longitude' => 2.2945,
                            'confidence' => 90,
                            'reasoning' => 'Distinctive iron lattice tower',
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

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('test.png', $this->tinyPngBytes()),
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $analysis = GeoAnalysis::find($response->json('id'));
        $this->assertNotNull($analysis);
        $this->assertSame('completed', $analysis->status);
        $this->assertStringStartsWith('/storage/', $analysis->image_url);

        Storage::disk('public')->assertExists($analysis->image_path);
    }

    private function tinyPngBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        try {
            imagepng($image);
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        imagedestroy($image);

        return $bytes;
    }
}
