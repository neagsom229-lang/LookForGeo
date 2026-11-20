<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiClient;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazyGeminiTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_poll_does_not_construct_gemini_service(): void
    {
        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'completed',
            'stage' => 4,
            'stage_label' => 'Locate',
            'progress' => 100,
            'image_path' => 'analyses/x.png',
            'result' => ['landmark_name' => 'X'],
        ]);

        $this->assertFalse(app()->resolved(GeminiService::class));
        $this->assertFalse(app()->resolved(GeminiClient::class));

        $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status")->assertOk();

        // The status endpoint must not build the AI client on every poll.
        $this->assertFalse(app()->resolved(GeminiService::class));
        $this->assertFalse(app()->resolved(GeminiClient::class));
    }

    public function test_gemini_client_construction_writes_no_init_log(): void
    {
        $logFile = storage_path('logs/laravel-test.log');
        $offset = file_exists($logFile) ? (int) filesize($logFile) : 0;

        app(GeminiClient::class);

        $written = (string) substr((string) file_get_contents($logFile), $offset);
        $this->assertStringNotContainsString('GeminiService initialized', $written);
    }
}
