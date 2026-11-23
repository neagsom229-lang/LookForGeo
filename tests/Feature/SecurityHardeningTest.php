<?php

namespace Tests\Feature;

use App\Jobs\DeleteCloudinaryImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_hsts_is_emitted_only_for_production_https(): void
    {
        $this->getJson('/api/health')->assertHeaderMissing('Strict-Transport-Security');

        config(['app.env' => 'production']);
        $this->withHeader('X-Forwarded-Proto', 'https')
            ->getJson('/api/health')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_session_id_is_regenerated_on_login(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password')]);

        $this->get('/login')->assertOk();
        $before = $this->app['session.store']->getId();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect();

        $this->assertNotSame($before, $this->app['session.store']->getId());
    }

    public function test_upload_with_fake_image_bytes_is_rejected(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('fake.png', 'this is not a PNG'),
        ])->assertStatus(422);
    }

    public function test_fetch_image_blocks_private_link_local_cgnat_and_non_http_urls(): void
    {
        $user = User::factory()->create();
        Http::fake(['*' => Http::response('image', 200, ['Content-Type' => 'image/png'])]);

        foreach ([
            'http://127.0.0.1/admin',
            'http://10.0.0.1/latest/meta-data',
            'http://169.254.169.254/latest/meta-data',
            'http://100.64.0.1/',
            'ftp://example.com/image.png',
        ] as $url) {
            $this->actingAs($user, 'web')
                ->getJson('/api/fetch-image?url='.urlencode($url))
                ->assertStatus(400);
        }

        Http::assertNothingSent();
    }

    public function test_fetch_image_validates_redirect_target_before_following_it(): void
    {
        $user = User::factory()->create();

        Http::fakeSequence()->push('', 302, ['Location' => 'http://127.0.0.1/internal']);

        $this->actingAs($user, 'web')
            ->getJson('/api/fetch-image?url='.urlencode('http://93.184.216.34/image.png'))
            ->assertStatus(400);

        // The private redirect target was rejected before a second request.
        Http::assertSentCount(1);
    }

    public function test_deleting_an_analysis_queues_cloudinary_asset_deletion(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'image_path' => 'analyses/x.png',
            'image_url' => 'https://res.cloudinary.com/demo/image/upload/v123/tracegeo/analyses/x.png',
        ]);

        $this->actingAs($user, 'web')->deleteJson("/api/analyze/{$analysis->id}")->assertOk();

        Queue::assertPushed(DeleteCloudinaryImageJob::class);
        $this->assertNull(GeoAnalysis::find($analysis->id));
    }

    public function test_csp_still_follows_app_url(): void
    {
        config()->set('app.url', 'https://study.example.com');

        $csp = $this->getJson('/api/health')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' https://study.example.com", (string) $csp);
    }

    public function test_cors_header_not_star_in_production(): void
    {
        // Real feature test: Origins must not be allowed wildcard in production.
        // The app must not emit Access-Control-Allow-Origin: * for any origin.
        config(['app.env' => 'production', 'app.debug' => false]);

        // /api/health should not reflect wildcard CORS
        $health = $this->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/health');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin: *',
            $health->headers->get('Access-Control-Allow-Origin') ?? '');

        // /api/search should not reflect wildcard CORS
        $search = $this->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/search');
        $this->assertStringNotContainsString('Access-Control-Allow-Origin: *',
            $search->headers->get('Access-Control-Allow-Origin') ?? '');
    }
}
