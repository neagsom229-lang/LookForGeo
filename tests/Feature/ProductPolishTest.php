<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPolishTest extends TestCase
{
    use RefreshDatabase;

    private function analysisFor(User $user, string $status): GeoAnalysis
    {
        return GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => $status,
            'stage' => 0,
            'stage_label' => 'Input',
            'progress' => 10,
            'image_path' => 'analyses/x.png',
            'result' => $status === 'completed' ? ['landmark_name' => 'Eiffel Tower'] : null,
        ]);
    }

    public function test_every_history_status_has_a_next_action(): void
    {
        $user = User::factory()->create();
        $this->analysisFor($user, 'completed');
        $this->analysisFor($user, 'processing');
        $this->analysisFor($user, 'failed');

        $response = $this->actingAs($user, 'web')->get('/history')->assertOk();

        $response->assertSee('View result', false)
            ->assertSee('Check progress', false)
            ->assertSee('Retry analysis', false);
    }

    public function test_empty_history_has_a_call_to_action(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')->get('/history')
            ->assertOk()
            ->assertSee('Start Analysis', false);
    }

    public function test_analysis_page_has_accessibility_hooks_and_result_actions(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web')->get('/analysis')->assertOk();

        $response->assertSee('Skip to analysis', false)
            ->assertSee('id="pcError" role="alert" aria-live="assertive"', false)
            ->assertSee('aria-label="Copy coordinates"', false)
            ->assertSee('aria-label="Save report"', false)
            ->assertSee('aria-label="Share result"', false)
            ->assertSee('id="confidenceEvidence"', false);
    }

    public function test_home_page_toast_is_announced_to_screen_readers(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="toast" role="status" aria-live="polite"', false);
    }
}
