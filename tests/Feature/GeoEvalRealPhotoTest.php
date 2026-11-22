<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Real-photo mode: CSV ground truth, licence column, banded accuracy. */
class GeoEvalRealPhotoTest extends TestCase
{
    private function makePhoto(string $path): void
    {
        $image = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($image);
        file_put_contents($path, (string) ob_get_clean());
        imagedestroy($image);
    }

    public function test_real_photo_mode_reports_banded_accuracy_and_warns_below_30(): void
    {
        $dir = sys_get_temp_dir().'/tg-realphotos-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $report = tempnam(sys_get_temp_dir(), 'tg-real-report');
        $calls = 0;

        try {
            $this->makePhoto($dir.'/a.png');
            $this->makePhoto($dir.'/b.png');
            // British spelling "licence" is accepted too.
            file_put_contents($dir.'/labels.csv',
                "file,landmark,city,country,latitude,longitude,licence\n"
                ."a.png,Spot A,Paris,France,48.8584,2.2945,\"Photo by A, CC BY 4.0\"\n"
                ."b.png,Spot B,London,United Kingdom,51.5007,-0.1246,\"Photo by B, CC BY 4.0\"\n"
            );

            config([
                'services.gemini.key' => 'AIzaSyFakeRealPhotoKey1234567890',
                'services.gemini.retries' => 0,
                'services.gemini.min_attempt_seconds' => 0,
            ]);
            Http::fake(function () use (&$calls) {
                $calls++;
                $confidence = $calls === 1 ? 90 : 40;

                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'text' => json_encode([
                                'landmark_name' => 'Spot',
                                'city' => 'Paris',
                                'country' => 'France',
                                'latitude' => 48.8584,
                                'longitude' => 2.2945,
                                'confidence' => $confidence,
                                'reasoning' => 'Visible landmark',
                                'description' => 'Scene',
                            ]),
                        ]]],
                    ]],
                ], 200);
            });

            $output = new BufferedOutput;
            $exit = Artisan::call('geo:eval', [
                '--live' => true,
                '--labels' => $dir.'/labels.csv',
                '--images' => $dir,
                '--report' => $report,
            ], $output);
            $text = $output->fetch();

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('real-photo labels', $text);
            $this->assertStringContainsString('need >=30', $text);
            $this->assertStringContainsString('Accuracy by predicted-confidence band', $text);
            $this->assertStringContainsString('median error (km)', $text);
            $this->assertStringContainsString('high:80-100', $text);
            $this->assertStringContainsString('low:0-49', $text);

            $json = json_decode((string) file_get_contents($report), true);
            $this->assertSame('real-photo', $json['dataset']);
            $this->assertSame(2, $json['count']);
            // The calibrated score is low on photos with no metadata, so both
            // rows land in the low band; both predictions match the truth.
            $this->assertSame(2, $json['metrics']['calibration']['low:0-49']['count']);
            $this->assertSame(0.0, (float) $json['metrics']['calibration']['low:0-49']['median_error_km']);

            fwrite(STDERR, "\n=== geo:eval real-photo ===\n".$text."\n");
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            @rmdir($dir);
            @unlink($report);
        }
    }

    public function test_labels_requires_images_directory(): void
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('geo:eval', ['--labels' => 'x.csv'], $output);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('must be supplied together', $output->fetch());
    }
}
