<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AnalysisApiController;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Step 1 — "make requirements less dumb": the public API docs (README,
     * docs.blade.php) promise GET /api/history to Sanctum bearer clients.
     *
     * routes/web.php also defined GET /api/history, and Laravel registers the
     * web file AFTER the api file (ApplicationBuilder::buildRoutingCallback)
     * while RouteCollection::addToCollections ASSIGNS rather than appends --
     * so the web route silently destroyed the documented API route and token
     * clients received 401. Pin the contract so it cannot regress.
     */
    public function test_get_api_history_is_served_by_the_documented_api_controller(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/api/history', 'GET'));

        $this->assertSame(
            AnalysisApiController::class.'@history',
            $route->getActionName(),
            'GET /api/history must be served by AnalysisApiController@history'
        );
    }

    /**
     * A Sanctum bearer client must actually be able to list its history.
     */
    public function test_sanctum_client_can_list_history(): void
    {
        $user = User::factory()->create();

        GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Locate',
            'progress' => 100,
            'image_path' => 'uploads/analyses/eiffel.png',
            'image_url' => 'https://example.com/eiffel.png',
            'result' => [
                'landmark_name' => 'Eiffel Tower',
                'latitude' => 48.8584,
                'longitude' => 2.2945,
                'confidence' => 95,
            ],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/history')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data', 'pagination'])
            ->assertJsonPath('data.0.landmark', 'Eiffel Tower');
    }

    /**
     * Anonymous callers must still be rejected (cost/privacy bearing).
     */
    public function test_anonymous_client_cannot_list_history(): void
    {
        $this->getJson('/api/history')->assertStatus(401);
    }
}
