<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->getJson('/api/test-apis')->assertStatus(401);
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
}
