<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** End-to-end result shape with the DEFAULT (slim off) schema. */
class ResultShapeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGemini(bool $withCoordinates): void
    {
        $body = [
            'landmark_name' => 'Wat Sampov Meas',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'confidence' => 90,
            'reasoning' => 'Distinctive temple architecture.',
            'description' => 'A temple by the river.',
            'vantage_point' => 'ground',
            'visual_clues' => ['temple', 'roof', 'river', 'stone'],
            'tags' => ['temple', 'cambodia'],
            'candidates' => [
                ['location' => 'Wat Sampov Meas', 'probability' => 88, 'reasoning' => 'Temple style'],
                ['location' => 'Royal Palace', 'probability' => 40, 'reasoning' => 'Nearby'],
            ],
        ];
        if ($withCoordinates) {
            $body['latitude'] = 11.5564;
            $body['longitude'] = 104.9282;
        }

        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode($body),
                ]]],
            ]],
        ], 200)]);
    }

    private function runJob(): GeoAnalysis
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/local.png', 'image-bytes');
        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Queued',
            'progress' => 10,
            'image_path' => 'analyses/local.png',
            'image_url' => '/storage/analyses/local.png',
        ]);
        (new AnalyzeImageJob($analysis->id, null, 'local.png'))->handle(app(GeminiService::class));

        return $analysis->refresh();
    }

    public function test_default_schema_stores_coordinates_country_and_candidates(): void
    {
        // phpunit.xml forces GEMINI_SLIM_SCHEMA=false (the default).
        config(['services.gemini.slim_schema' => false]);
        $this->fakeGemini(true);

        $analysis = $this->runJob();
        $result = $analysis->result;

        $this->assertSame('completed', $analysis->status);
        $this->assertArrayHasKey('latitude', $result);
        $this->assertArrayHasKey('longitude', $result);
        $this->assertSame('Cambodia', $result['country']);
        $this->assertNotEmpty($result['candidates']);
        $this->assertFalse((bool) ($result['coordinates_missing'] ?? false));
        $this->assertGreaterThan(0, (int) $result['confidence']);
    }

    public function test_missing_coordinates_still_complete_but_flagged_and_capped(): void
    {
        config(['services.gemini.slim_schema' => false]);
        $this->fakeGemini(false);

        $analysis = $this->runJob();
        $result = $analysis->result;

        // The analysis-21 shape: completed but not pinnable.
        $this->assertSame('completed', $analysis->status);
        $this->assertTrue((bool) $result['coordinates_missing']);
        $this->assertLessThanOrEqual(49, (int) $result['confidence']);
        $this->assertTrue((bool) $result['low_confidence']);
        $this->assertStringContainsString(
            'location could not be pinned',
            strtolower(implode(' ', $result['confidence_factors']))
        );
    }

    public function test_explain_prints_result_shape_for_a_result_without_coordinates(): void
    {
        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'completed',
            'result' => [
                'landmark_name' => 'Wat Sampov Meas',
                'country' => 'Cambodia',
                'city' => 'Phnom Penh',
                'confidence' => 30,
                'coordinates_missing' => true,
            ],
        ]);

        $output = new BufferedOutput;
        $exit = Artisan::call('analyses:explain', ['id' => $analysis->id], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Result shape:', $text);
        $this->assertStringContainsString('latitude', $text);
        $this->assertStringContainsString('longitude', $text);
        $this->assertStringContainsString('country', $text);
        $this->assertStringContainsString('city', $text);
        $this->assertStringContainsString('candidates', $text);
        $this->assertMatchesRegularExpression('/latitude\s*\|\s*missing/', $text);
        $this->assertStringContainsString('coordinates_missing=true', $text);
    }

    public function test_status_endpoint_exposes_the_flag(): void
    {
        $this->fakeGemini(false);
        $analysis = $this->runJob();
        $user = GeoAnalysis::find($analysis->id)->user;

        $response = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");

        $response->assertOk()
            ->assertJsonPath('data.coordinates_missing', true);
    }
}
