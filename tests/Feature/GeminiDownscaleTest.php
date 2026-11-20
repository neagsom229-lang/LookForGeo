<?php

namespace Tests\Feature;

use App\Services\Gemini\GeminiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiDownscaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'AIzaSyFakeDownscaleKey1234567890',
            'services.gemini.compress_images' => true,
            'services.gemini.max_image_dimension' => 1024,
            'services.gemini.compress_threshold_bytes' => 1000,
            'services.gemini.retry_backoff_ms' => 0,
        ]);
    }

    public function test_large_image_is_downscaled_to_1024_before_sending(): void
    {
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['landmark_name' => 'X']),
                    ]]],
                ]],
            ], 200),
        ]);

        $original = $this->noisyPng(1400, 1200);
        $originalSize = getimagesizefromstring($original);
        $this->assertGreaterThan(1024, $originalSize[0]);

        app(GeminiClient::class)->analyzeWithFallback($original, 'prompt');

        $sentBytes = null;
        Http::assertSent(function ($request) use (&$sentBytes) {
            $data = $request->data();
            $b64 = $data['contents'][0]['parts'][1]['inline_data']['data'] ?? null;
            if ($b64) {
                $sentBytes = base64_decode($b64);
            }

            return true;
        });

        $this->assertNotNull($sentBytes);
        $sentSize = getimagesizefromstring($sentBytes);
        $this->assertLessThanOrEqual(1024, max($sentSize[0], $sentSize[1]));
    }

    private function noisyPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, $color);
            }
        }
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
