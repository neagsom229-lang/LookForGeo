<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CostGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'confidence' => 90,
                            'reasoning' => 'Tower',
                            'description' => 'Tower',
                            'vantage_point' => 'ground',
                            'visual_clues' => ['a', 'b'],
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

    public function test_per_user_daily_quota_blocks_the_next_real_upload(): void
    {
        config(['services.gemini.daily_limit_per_user' => 1, 'services.gemini.daily_limit_global' => 0]);
        $user = User::factory()->create();

        $first = $this->upload($user, $this->png('a'));
        $second = $this->upload($user, $this->png('b'));

        $first->assertOk()->assertJsonPath('success', true);
        $second->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertHeader('Retry-After', '86400');
        $this->assertStringContainsString('daily analysis limit', $second->json('message'));
        $this->assertSame(1, GeoAnalysis::count());
    }

    public function test_global_kill_switch_blocks_every_user(): void
    {
        config(['services.gemini.daily_limit_global' => 1, 'services.gemini.daily_limit_per_user' => 0]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->upload($firstUser, $this->png('a'))->assertOk();
        $blocked = $this->upload($secondUser, $this->png('b'));

        $blocked->assertStatus(429);
        $this->assertStringContainsString('daily analysis limit has been reached', $blocked->json('message'));
    }

    public function test_cached_repeat_upload_is_not_blocked_by_quota(): void
    {
        config(['services.gemini.daily_limit_per_user' => 1, 'services.gemini.daily_limit_global' => 0]);
        $user = User::factory()->create();
        $bytes = $this->png('same');

        $this->upload($user, $bytes)->assertOk();
        $cached = $this->upload($user, $bytes);

        $cached->assertOk()->assertJsonPath('cached', true);
    }

    public function test_quota_is_scoped_per_user(): void
    {
        config(['services.gemini.daily_limit_per_user' => 1, 'services.gemini.daily_limit_global' => 0]);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->upload($firstUser, $this->png('a'))->assertOk();
        $this->upload($secondUser, $this->png('b'))->assertOk();

        $this->assertSame(2, GeoAnalysis::count());
    }

    private function png(string $seed): string
    {
        $image = imagecreatetruecolor(12, 12);
        $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
        imagefilledrectangle($image, 0, 0, 11, 11, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
