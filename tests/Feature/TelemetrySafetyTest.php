<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Telemetry columns are optional: a missing column must not fail analysis. */
class TelemetrySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_telemetry_columns_do_not_fail_the_pipeline(): void
    {
        Storage::fake('public');
        Cache::forget('analysis_telemetry_columns');
        Schema::shouldReceive('hasColumn')->with('geo_analyses', \Mockery::any())->andReturn(false);

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'landmark_name' => 'Eiffel Tower',
                        'city' => 'Paris',
                        'country' => 'France',
                        'confidence' => 80,
                        'reasoning' => 'Tower',
                        'description' => 'Tower',
                    ]),
                ]]],
            ]],
        ], 200)]);

        $user = User::factory()->create();
        $response = $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('x.png', $this->pngBytes()),
        ]);

        $response->assertOk();
        $analysis = GeoAnalysis::find($response->json('id'));
        $this->assertSame('completed', $analysis->status);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
