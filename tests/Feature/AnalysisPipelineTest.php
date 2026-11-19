<?php

namespace Tests\Feature;

use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalysisPipelineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Characterization test (pre-merge): POST /api/analyze → sync queue
     * → AnalyzeImageJob → GeoAnalysis completed with expected fields.
     *
     * Http::fake() stubs every outbound HTTP call (Gemini + any enrichment)
     * so the test never touches the network. Cloudinary is explicitly
     * disabled so no cloud upload happens either.
     */
    public function test_upload_dispatches_job_and_completes_analysis(): void
    {
        Storage::fake('public');

        // Disable Cloudinary so uploadToCloudinary() skips silently
        Config::set('services.cloudinary.cloud_name', null);
        Config::set('services.cloudinary.api_key', null);
        Config::set('services.cloudinary.api_secret', null);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'text' => json_encode([
                                'landmark_name' => 'Eiffel Tower',
                                'city' => 'Paris',
                                'country' => 'France',
                                'region' => 'Île-de-France',
                                'latitude' => 48.8584,
                                'longitude' => 2.2945,
                                'confidence' => 90,
                                'reasoning' => 'Recognizable iron lattice tower with riveted structure',
                                'negative_evidence' => 'None',
                                'vantage_point' => 'ground',
                                'visual_clues' => ['iron lattice', 'tower', 'rivets', 'Seine', 'Paris'],
                                'tags' => ['landmark', 'tower', 'paris'],
                                'description' => 'The Eiffel Tower',
                                'type' => 'landmark',
                                'continent' => 'Europe',
                                'timezone' => 'Europe/Paris',
                                'nearby_landmarks' => [],
                                'wikipedia_url' => null,
                                'nearest_airport' => null,
                                'candidates' => [[
                                    'location' => 'Eiffel Tower',
                                    'city' => 'Paris',
                                    'country' => 'France',
                                    'latitude' => 48.8584,
                                    'longitude' => 2.2945,
                                    'probability' => 92,
                                    'reasoning' => 'Distinctive structure',
                                ]],
                            ]),
                        ]],
                    ],
                ]],
            ], 200),
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/analyze', [
                'image' => UploadedFile::fake()->createWithContent('eiffel.png', $this->tinyPngBytes()),
            ]);

        $response->assertStatus(202);
        $this->assertTrue($response->json('success'));

        $id = $response->json('id');

        $analysis = GeoAnalysis::find($id);
        $this->assertNotNull($analysis, 'Analysis record must exist');
        $this->assertSame('completed', $analysis->status, 'Analysis error: '.($analysis->error ?? '(none)'));
        $this->assertSame(100, (int) $analysis->progress);
        $this->assertSame(4, (int) $analysis->stage);
        $this->assertNotNull($analysis->finished_at);

        $result = $analysis->result;
        $this->assertSame('Eiffel Tower', $result['landmark_name']);
        $this->assertSame('Paris', $result['city']);
        $this->assertSame('France', $result['country']);
        $this->assertSame(48.8584, (float) $result['latitude']);
        $this->assertSame(2.2945, (float) $result['longitude']);
        $this->assertGreaterThanOrEqual(80, (int) $result['confidence']);
        $this->assertNotEmpty($result['reasoning']);
    }

    private function tinyPngBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        ob_start();
        try {
            imagepng($image);
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        imagedestroy($image);

        return $bytes;
    }
}
