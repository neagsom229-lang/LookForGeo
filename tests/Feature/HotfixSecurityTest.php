<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Hotfix regression tests: the smallest set that proves the public
 * cost-bearing endpoints are closed and analysis rows are owner-scoped.
 */
class HotfixSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_cost_bearing_routes_return_401_without_accept_header(): void
    {
        $requests = [
            ['post', '/api/identify'],
            ['post', '/api/identify-gps'],
            ['post', '/api/extract-exif'],
            ['get', '/api/nearby'],
            ['get', '/api/search'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->{$method}($uri)->assertStatus(401);
        }

        $this->post('/api/identify')
            ->assertStatus(401)
            ->assertJson(['message' => 'Unauthenticated.']);
    }

    public function test_api_test_route_is_removed(): void
    {
        $this->getJson('/api/test-apis')->assertStatus(404);
    }

    public function test_public_health_check_still_works(): void
    {
        $this->getJson('/api/health')->assertOk();
    }

    public function test_api_history_route_is_not_shadowed_and_is_owner_scoped(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->analysisFor($owner);
        $this->analysisFor($other);

        Sanctum::actingAs($owner);

        $this->getJson('/api/history')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.landmark', 'Eiffel Tower');
    }

    public function test_api_history_rejects_anonymous_requests(): void
    {
        $this->get('/api/history')->assertStatus(401);
    }

    public function test_cross_user_status_and_results_return_404(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $analysis = $this->analysisFor($owner);

        $this->actingAs($intruder, 'web');

        $this->getJson("/api/analyze/{$analysis->id}/status")->assertStatus(404);
        $this->getJson("/api/results/{$analysis->id}")->assertStatus(404);
    }

    public function test_owner_can_read_their_own_status_and_results(): void
    {
        $owner = User::factory()->create();
        $analysis = $this->analysisFor($owner);

        $this->actingAs($owner, 'web');

        $this->getJson("/api/analyze/{$analysis->id}/status")->assertOk();
        $this->getJson("/api/results/{$analysis->id}")->assertOk()
            ->assertJsonPath('data.landmark_name', 'Eiffel Tower');
    }

    public function test_fetch_image_blocks_private_and_reserved_hosts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        foreach ([
            'http://127.0.0.1/',
            'http://localhost/',
            'http://169.254.169.254/latest/meta-data/',
            'http://100.64.0.1/',
            'http://[::1]/',
            'file:///etc/passwd',
        ] as $url) {
            $status = $this->getJson('/api/fetch-image?url='.urlencode($url))->getStatusCode();

            // 422 = rejected by URL validation; 400 = rejected by the SSRF guard.
            // Either way the request never leaves the server.
            $this->assertContains($status, [400, 422], "{$url} was not blocked (HTTP {$status})");
        }

        // A syntactically valid URL must reach -- and be stopped by -- the guard.
        $this->getJson('/api/fetch-image?url='.urlencode('http://127.0.0.1/'))
            ->assertStatus(400);
    }

    public function test_fetch_image_still_allows_public_hosts(): void
    {
        Http::fake([
            'http://93.184.216.34/*' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $this->getJson('/api/fetch-image?url='.urlencode('http://93.184.216.34/photo.jpg'))
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    private function analysisFor(User $owner): GeoAnalysis
    {
        return GeoAnalysis::create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Locate',
            'progress' => 100,
            'image_path' => 'landmarks/test.jpg',
            'image_url' => 'https://example.com/test.jpg',
            'result' => [
                'landmark_name' => 'Eiffel Tower',
                'city' => 'Paris',
                'country' => 'France',
                'latitude' => 48.8584,
                'longitude' => 2.2945,
                'confidence' => 95,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }
}
