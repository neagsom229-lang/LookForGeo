<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiClient;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GeminiTokenTest extends TestCase
{
    public function test_usage_metadata_is_logged_per_attempt_and_stored_for_stats(): void
    {
        Log::spy();
        config(['services.gemini.key' => 'AIzaSyFakeTokenKey1234567890']);
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
            'usageMetadata' => [
                'promptTokenCount' => 1234,
                'candidatesTokenCount' => 200,
                'thoughtsTokenCount' => 77,
            ],
        ], 200)]);

        $result = app(GeminiService::class)->analyzeGeolocation('img', []);

        $this->assertSame(1234, $result['prompt_tokens']);
        $this->assertSame(200, $result['output_tokens']);
        $this->assertSame(77, $result['thought_tokens']);
        $this->assertArrayHasKey('gemini_ms', $result);
        $this->assertIsInt($result['gemini_ms']);
        Log::shouldHaveReceived('info')->with('Gemini attempt', Mockery::on(function ($context) {
            return ($context['prompt_tokens'] ?? 0) === 1234
                && ($context['output_tokens'] ?? 0) === 200
                && ($context['thought_tokens'] ?? 0) === 77
                && array_key_exists('elapsed_ms', $context);
        }));
    }

    public function test_small_image_is_sent_unchanged_because_it_is_below_the_threshold(): void
    {
        // Confirms the user's log observation: for images under
        // compress_threshold_bytes (default 1 MB) image_bytes equals the
        // original file size. No downscale happens in that case.
        config(['services.gemini.compress_threshold_bytes' => 1_000_000]);
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagepng($image);
        $original = (string) ob_get_clean();

        $prepared = app(GeminiClient::class)->prepareImageForModel($original);

        $this->assertSame($original, $prepared['data']);
        $this->assertSame(strlen($original), strlen($prepared['data']));
    }

    public function test_large_image_is_downscaled_to_1024(): void
    {
        // Force the PNG encoder so the result is identical whether or not the
        // runner's GD build has JPEG. A low threshold triggers the downscale
        // path without allocating a huge fixture.
        config([
            'services.gemini.compress_threshold_bytes' => 1000,
            'services.gemini.image_encoder' => 'png',
        ]);
        $image = imagecreatetruecolor(1100, 900);
        $palette = [];
        for ($i = 0; $i < 256; $i++) {
            $palette[] = imagecolorallocate($image, $i, random_int(0, 255), random_int(0, 255));
        }
        for ($y = 0; $y < 900; $y++) {
            for ($x = 0; $x < 1100; $x++) {
                imagesetpixel($image, $x, $y, $palette[random_int(0, 255)]);
            }
        }
        ob_start();
        imagepng($image);
        $original = (string) ob_get_clean();
        imagedestroy($image);
        $this->assertGreaterThan(1000, strlen($original), 'fixture must exceed threshold');

        $prepared = app(GeminiClient::class)->prepareImageForModel($original);
        $sent = getimagesizefromstring($prepared['data']);

        $this->assertLessThanOrEqual(1024, max($sent[0], $sent[1]));
        $this->assertSame('image/png', $prepared['mime']);
        $this->assertLessThan(strlen($original), strlen($prepared['data']));
    }

    public function test_auto_encoder_is_valid_with_or_without_gd_jpeg(): void
    {
        config([
            'services.gemini.compress_threshold_bytes' => 1000,
            'services.gemini.image_encoder' => 'auto',
        ]);
        $image = imagecreatetruecolor(1100, 900);
        ob_start();
        imagepng($image);
        $original = (string) ob_get_clean();
        imagedestroy($image);

        $prepared = app(GeminiClient::class)->prepareImageForModel($original);
        $sent = getimagesizefromstring($prepared['data']);

        $this->assertLessThanOrEqual(1024, max($sent[0], $sent[1]));
        $this->assertContains($prepared['mime'], ['image/jpeg', 'image/png']);
    }
}
