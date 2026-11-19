<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 0 (S2): cost-bearing endpoints must never be callable
     * without authentication (they reach Gemini / paid APIs).
     */
    public function test_cost_bearing_endpoints_require_authentication(): void
    {
        $this->postJson('/api/identify', [])->assertStatus(401);
        $this->postJson('/api/identify-gps', [])->assertStatus(401);
        $this->postJson('/api/extract-exif', [])->assertStatus(401);
        $this->getJson('/api/nearby')->assertStatus(401);
        $this->getJson('/api/search')->assertStatus(401);
    }

    /**
     * S3: debug/test routes were deleted, so they must not exist at all.
     */
    public function test_removed_debug_and_test_routes_return_404(): void
    {
        $this->getJson('/api/health')->assertOk(); // sanity: app still serves
        $this->getJson('/api/test-apis')->assertStatus(404);
        $this->getJson('/debug-jobs')->assertStatus(404);
        $this->getJson('/debug-file/whatever.jpg')->assertStatus(404);
        $this->getJson('/test-queue')->assertStatus(404);
        $this->getJson('/test-gemini')->assertStatus(404);
    }

    /**
     * Phase 0 (S4): an authenticated user must not be able to read,
     * delete or retry another user's analysis (IDOR).
     */
    public function test_user_cannot_access_another_users_analysis(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $analysis = GeoAnalysis::create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Locate',
            'progress' => 100,
            'image_path' => 'landmarks/abc.jpg',
            'image_url' => 'https://res.cloudinary.com/demo/image/upload/v1/abc.jpg',
            'result' => [
                'landmark_name' => 'Eiffel Tower',
                'confidence' => 95,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($intruder, 'web');

        $this->getJson("/api/analyze/{$analysis->id}/status")->assertStatus(404);
        $this->getJson("/api/results/{$analysis->id}")->assertStatus(404);
        $this->deleteJson("/api/analyze/{$analysis->id}")->assertStatus(404);
        $this->postJson("/api/analyze/{$analysis->id}/retry")->assertStatus(404);
    }

    /**
     * Web session auth must pass for cost-bearing endpoints.
     * NOTE: Http::fake stubs ALL outbound HTTP (Gemini/OSM/etc.) so the
     * test never hits the network.
     */
    public function test_web_session_authenticated_user_can_reach_cost_bearing_endpoints(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['landmark_name' => 'Eiffel Tower', 'city' => 'Paris', 'country' => 'France', 'region' => 'Ile-de-France', 'latitude' => 48.8584, 'longitude' => 2.2945, 'confidence' => 90, 'reasoning' => 'Distinctive tower', 'description' => 'The Eiffel Tower', 'vantage_point' => 'ground', 'visual_clues' => ['tower'], 'tags' => ['landmark'], 'candidates' => []])]]]]]], 200)]);

        $user = User::factory()->create();
        $this->actingAs($user, 'web');

        $identify = $this->postJson('/api/identify', [
            'image' => UploadedFile::fake()->image('eiffel.png', 400, 300),
        ]);
        $this->assertNotEquals(401, $identify->status(), 'POST /api/identify must not be 401 for a web session');

        $search = $this->getJson('/api/search?query=Paris');
        $this->assertNotEquals(401, $search->status(), 'GET /api/search must not be 401 for a web session');
    }

    /**
     * Sanctum bearer token auth must pass for cost-bearing endpoints.
     */
    public function test_sanctum_token_authenticated_user_can_reach_cost_bearing_endpoints(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['landmark_name' => 'Eiffel Tower', 'city' => 'Paris', 'country' => 'France', 'region' => 'Ile-de-France', 'latitude' => 48.8584, 'longitude' => 2.2945, 'confidence' => 90, 'reasoning' => 'Distinctive tower', 'description' => 'The Eiffel Tower', 'vantage_point' => 'ground', 'visual_clues' => ['tower'], 'tags' => ['landmark'], 'candidates' => []])]]]]]], 200)]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $identify = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/identify', [
                'image' => UploadedFile::fake()->image('eiffel.png', 400, 300),
            ]);
        $this->assertNotEquals(401, $identify->status(), 'POST /api/identify must not be 401 for a Sanctum token');

        $search = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/search?query=Paris');
        $this->assertNotEquals(401, $search->status(), 'GET /api/search must not be 401 for a Sanctum token');
    }

    /**
     * Anonymous /api/* requests must return 401 regardless of the Accept header.
     */
    public function test_anonymous_api_search_returns_401_regardless_of_accept(): void
    {
        $browser = $this->get('/api/search?query=paris', ['Accept' => 'text/html,application/xhtml+xml']);
        $browser->assertStatus(401);

        $noAccept = $this->get('/api/search?query=paris');
        $noAccept->assertStatus(401);
    }

    /**
     * Phase 0 (S4): the owner must still be able to read their own analysis.
     */
    public function test_user_can_access_their_own_analysis(): void
    {
        $owner = User::factory()->create();

        $analysis = GeoAnalysis::create([
            'user_id' => $owner->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Locate',
            'progress' => 100,
            'image_path' => 'landmarks/abc.jpg',
            'image_url' => 'https://res.cloudinary.com/demo/image/upload/v1/abc.jpg',
            'result' => [
                'landmark_name' => 'Eiffel Tower',
                'confidence' => 95,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($owner, 'web');

        $this->getJson("/api/analyze/{$analysis->id}/status")->assertOk();
        $this->getJson("/api/results/{$analysis->id}")->assertOk();
    }

    /**
     * Unauthenticated callers must get 401 for cost-bearing endpoints --
     * including browser-shaped requests without any JSON Accept header
     * (previously those got a 302 redirect to /login).
     */
    public function test_unauthenticated_user_cannot_reach_cost_bearing_endpoints(): void
    {
        $identify = $this->postJson('/api/identify', [
            'image' => UploadedFile::fake()->image('eiffel.png', 400, 300),
        ]);
        $this->assertSame(401, $identify->status(), 'POST /api/identify must be 401 when anonymous');

        $search = $this->getJson('/api/search?query=Paris');
        $this->assertSame(401, $search->status(), 'GET /api/search must be 401 when anonymous');
    }
}
