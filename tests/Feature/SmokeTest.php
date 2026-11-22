<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * End-to-end smoke: health → register → login → upload → status=completed
     * with valid coordinates. Gemini is faked (Http::fake) so this runs offline.
     */
    public function test_full_local_workflow(): void
    {
        self::stage("\nsmoke stage=health");
        $this->getJson('/api/health')->assertOk();

        self::stage("\nsmoke stage=register");
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
                            'reasoning' => 'Distinctive iron lattice tower with riveted structure',
                            'description' => 'The Eiffel Tower',
                            'vantage_point' => 'ground',
                            'visual_clues' => ['iron lattice', 'tower', 'rivets', 'Seine', 'Paris'],
                            'tags' => ['landmark', 'tower', 'paris'],
                            'candidates' => [],
                        ]),
                    ]]],
                ]],
            ], 200),
        ]);

        $register = $this->post('/register', [
            'name' => 'Smoke User',
            'email' => 'smoke@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);
        $register->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'smoke@example.com']);

        self::stage("\nsmoke stage=login");
        $this->post('/login', ['email' => 'smoke@example.com', 'password' => 'password'])
            ->assertRedirect();

        $user = User::where('email', 'smoke@example.com')->firstOrFail();

        self::stage("\nsmoke stage=upload");
        $upload = $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('smoke.png', $this->tinyPngBytes()),
        ]);
        $upload->assertOk();
        $upload->assertJsonPath('success', true);

        $id = $upload->json('id');

        self::stage("\nsmoke stage=status");
        $status = $this->actingAs($user, 'web')->getJson("/api/analyze/{$id}/status");
        $status->assertOk();
        $this->assertSame('completed', $status->json('status'));
        $this->assertSame(100, (int) $status->json('progress'));

        self::stage("\nsmoke stage=result");
        $result = $status->json('result');
        $this->assertNotEmpty($result['landmark_name']);
        $this->assertEquals(48.8584, (float) $result['latitude']);
        $this->assertEquals(2.2945, (float) $result['longitude']);

        self::stage("\nsmoke result landmark={$result['landmark_name']} coords={$result['latitude']},{$result['longitude']}\n");
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

    /** Keep stage tracing out of normal test output. */
    private static function stage(string $message): void
    {
        if (getenv('TEST_VERBOSE') === '1') {
            fwrite(STDERR, $message);
        }
    }
}
