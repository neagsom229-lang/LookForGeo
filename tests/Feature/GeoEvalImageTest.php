<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class GeoEvalImageTest extends TestCase
{
    private function fakeGemini(): void
    {
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
    }

    public function test_image_mode_prints_a_row_per_model_with_thinking_and_category(): void
    {
        $this->fakeGemini();
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        $path = tempnam(sys_get_temp_dir(), 'tg-eval').'.png';
        file_put_contents($path, (string) ob_get_clean());

        $output = new BufferedOutput;
        try {
            $exit = Artisan::call('geo:eval', [
                '--image' => $path,
                '--models' => 'gemini-model-a,gemini-model-b',
            ], $output);
        } finally {
            @unlink($path);
        }
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('gemini-model-a', $text);
        $this->assertStringContainsString('gemini-model-b', $text);
        $this->assertStringContainsString('thinking level', $text);
        $this->assertStringContainsString('category', $text);
        $this->assertStringContainsString('ms min/median/max', $text);
        $this->assertStringContainsString('minimal', $text);
    }

    public function test_live_mode_warns_when_fewer_than_30_real_photos(): void
    {
        config(['services.gemini.key' => 'AIzaSyFakeEvalKey1234567890']);
        $this->fakeGemini();

        $output = new BufferedOutput;
        $exit = Artisan::call('geo:eval', ['--live' => true, '--limit' => 2, '--report' => tempnam(sys_get_temp_dir(), 'tg-eval-report')], $output);
        $text = $output->fetch();

        $this->assertSame(0, $exit);
        $this->assertMatchesRegularExpression('/Only \d+ real labeled photos \(need >=30\)/', $text);
        $this->assertStringContainsString('calibration', strtolower($text));
    }
}
