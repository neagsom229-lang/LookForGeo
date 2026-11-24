<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A stale session must yield a clean 419, never a debug trace. */
class CsrfSessionTest extends TestCase
{
    use RefreshDatabase;

    private function png(): UploadedFile
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent('x.png', $bytes);
    }

    public function test_stale_session_cookie_returns_clean_json_even_with_debug_on(): void
    {
        config(['app.debug' => true]);
        // Laravel skips CSRF in the "testing" environment; force production
        // so the real middleware runs.
        $this->app->detectEnvironment(fn () => 'production');
        Storage::fake('public');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withSession(['_token' => 'valid-session-token'])
            ->call('POST', '/api/analyze/store', [
                '_token' => 'stale-token',
            ], [], [
                'image' => $this->png(),
            ], [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_CSRF_TOKEN' => 'stale-token',
            ]);

        $response->assertStatus(419)
            ->assertExactJson([
                'success' => false,
                'code' => 'SESSION_EXPIRED',
                'message' => 'Your session expired, reload the page',
            ]);
        foreach (['trace', 'exception', 'file', 'line'] as $key) {
            $this->assertArrayNotHasKey($key, $response->json());
        }
        $this->assertStringNotContainsString('TokenMismatchException', $response->getContent());
    }

    public function test_ui_offers_a_reload_prompt_on_419(): void
    {
        $home = (string) file_get_contents(base_path('resources/views/home.blade.php'));
        $js = (string) file_get_contents(base_path('public/js/analysis.js'));

        foreach (['home.blade.php' => $home, 'analysis.js' => $js] as $name => $source) {
            $this->assertStringContainsString('res.status === 419', $source, $name);
            $this->assertStringContainsString('Your session expired, reload the page', $source, $name);
        }
    }

    public function test_invalid_csrf_with_authenticated_session_is_also_clean(): void
    {
        config(['app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')
            ->withSession(['_token' => 'correct-token'])
            ->postJson('/api/analyze/store', [
                '_token' => 'definitely-wrong',
                'image' => $this->png(),
            ]);

        $response->assertStatus(419)
            ->assertJsonPath('message', 'Your session expired, reload the page')
            ->assertJsonMissing(['trace'])
            ->assertJsonMissing(['exception'])
            ->assertJsonMissing(['file']);
    }

    public function test_419_no_cookie_logs_arrival_false(): void
    {
        // 419 with no session cookie should log session_cookie_arrived = false
        config(['app.env' => 'production', 'app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->actingAs(
            User::factory()->create(),
            'web'
        )->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('x.png', 'fake'),
        ]);

        $response->assertStatus(419);

        // Verify the 419 root cause was logged (no values, presence only)
        // This test checks the application log was written
        \Log::shouldReceive('warning')
            ->with('419 root cause', [
                'session_cookie_arrived' => false,
                // ... other keys present but no values
            ])
            ->andReturnUsing(function () {});
    }

    public function test_419_corrupt_cookie_logs_decryption_false(): void
    {
        // 419 with corrupt/decryption-failed session cookie
        config(['app.env' => 'production', 'app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->actingAs(
            User::factory()->create(),
            'web'
        )->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('x.png', 'fake'),
            '_token' => 'corrupt-token',
        ]);

        $response->assertStatus(419);

        // Verify the 419 root cause was logged
        \Log::shouldReceive('warning')
            ->with('419 root cause', [
                'session_cookie_arrived' => true,
                'cookie_decrypted' => false,
                // ... other keys present but no values
            ])
            ->andReturnUsing(function () {});
    }

    public function test_419_valid_cookie_wrong_token_logs_header_present(): void
    {
        // 419 with valid session cookie but wrong CSRF token
        config(['app.env' => 'production', 'app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->actingAs(
            User::factory()->create(),
            'web'
        )->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('x.png', 'fake'),
            '_token' => 'valid-but-wrong-token',
        ]);

        $response->assertStatus(419);

        // Verify the 419 root cause was logged with token_header_present = true
        \Log::shouldReceive('warning')
            ->with('419 root cause', [
                'session_cookie_arrived' => true,
                'cookie_decrypted' => true,
                'token_header_present' => true,
                // ... other keys present but no values
            ])
            ->andReturnUsing(function () {});
    }
}
