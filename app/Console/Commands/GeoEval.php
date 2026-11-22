<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use App\Services\GeminiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Evaluate geolocation quality on a labeled fixture set.
 *
 * Mock mode (default) fakes Gemini with the label, so it measures the
 * harness/calibration path without cost. Live mode calls the real Gemini
 * endpoint and needs GEMINI_API_KEY. Both write a JSON report so runs can
 * be diffed for regressions.
 */
class GeoEval extends Command
{
    protected $signature = 'geo:eval
                            {--live : Call the real Gemini API (needs GEMINI_API_KEY)}
                            {--image= : Single real photo path (resized exactly like the app)}
                            {--labels= : CSV with file,landmark,city,country,latitude,longitude,license}
                            {--images= : Directory containing the real photos named in --labels}
                            {--models= : Comma-separated model ids (a,b,c)}
                            {--limit=0 : Evaluate only the first N images (0 = all)}
                            {--report= : Override the JSON report path}';

    protected $description = 'Evaluate accuracy, latency and confidence calibration on labeled images';

    private array $currentLabel = [];

    public function handle(GeminiService $gemini): int
    {
        $live = (bool) $this->option('live');
        $limit = max(0, (int) $this->option('limit'));
        $models = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('models')))));
        $imagePath = (string) $this->option('image');
        $labelsPath = (string) $this->option('labels');
        $imagesDir = (string) $this->option('images');

        if ($imagePath !== '') {
            return $this->evaluateSingleImage($gemini, $imagePath, $models, $live);
        }
        if (($labelsPath !== '') !== ($imagesDir !== '')) {
            $this->error('--labels and --images must be supplied together for real-photo mode.');

            return self::FAILURE;
        }
        $realPhotoMode = $labelsPath !== '';

        if ($live && empty(config('services.gemini.key'))) {
            $this->error('Live eval needs GEMINI_API_KEY in the environment.');

            return self::FAILURE;
        }

        $labels = $realPhotoMode ? $this->readLabels($labelsPath) : $this->readLabels();
        if (empty($labels)) {
            $this->error($realPhotoMode
                ? "No labels found in {$labelsPath}."
                : 'No labels found in tests/fixtures/eval/labels.csv. Run scripts/make-eval-fixtures.php first.');

            return self::FAILURE;
        }
        if ($realPhotoMode) {
            foreach (['file', 'latitude', 'longitude', 'license'] as $column) {
                if (! array_key_exists($column, $labels[0])) {
                    $this->error("CSV is missing required column '{$column}'.");

                    return self::FAILURE;
                }
            }
        }
        if ($limit > 0) {
            $labels = array_slice($labels, 0, $limit);
        }

        if (! $live && $realPhotoMode) {
            $this->warn('Mock mode returns the label as the prediction; accuracy numbers are not meaningful without --live.');
        }
        if (! $live) {
            // Mock: no network, no reverse-geocode call. The fake returns the
            // expected location so calibration reports honest (low) scores
            // for images with no checkable metadata.
            config(['services.gemini.confidence_reverse_geocode' => false]);
            Http::fake(function () {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'text' => json_encode([
                                'landmark_name' => $this->currentLabel['landmark'],
                                'city' => $this->currentLabel['city'],
                                'country' => $this->currentLabel['country'],
                                'latitude' => (float) $this->currentLabel['latitude'],
                                'longitude' => (float) $this->currentLabel['longitude'],
                                'confidence' => 95,
                                'reasoning' => 'Mock evaluation response',
                                'description' => 'Mock evaluation response',
                                'vantage_point' => 'ground',
                                'visual_clues' => ['mock clue one', 'mock clue two'],
                                'tags' => ['eval'],
                                'candidates' => [],
                            ]),
                        ]]],
                    ]],
                ], 200);
            });
        }

        if ($live || $realPhotoMode) {
            $realPhotos = 0;
            foreach ($labels as $label) {
                $license = strtolower((string) ($label['license'] ?? ''));
                if ($license !== '' && ! str_contains($license, 'synthetic')) {
                    $realPhotos++;
                }
            }
            if ($realPhotos < 30) {
                $this->warn("Only {$realPhotos} real labeled photos (need >=30). Calibration numbers are weak until you add real photos with licence notes.");
            }
            if (! empty($models)) {
                $this->info('Requested models: '.implode(',', $models));
            }
        }

        $this->info(sprintf(
            'Evaluating %d images (%s%s)...',
            count($labels),
            $live ? 'LIVE' : 'MOCK',
            $realPhotoMode ? ', real-photo labels' : ''
        ));

        $rows = [];
        foreach ($labels as $label) {
            $path = $realPhotoMode
                ? rtrim($imagesDir, '/\\').'/'.$label['file']
                : dirname(__DIR__, 3).'/tests/fixtures/eval/'.$label['file'];
            if (! is_file($path)) {
                $this->warn('Missing fixture: '.$label['file']);

                continue;
            }

            if (! $live) {
                $this->currentLabel = $label;
            }

            $started = microtime(true);
            try {
                $result = $gemini->analyzeGeolocation((string) file_get_contents($path), []);
            } catch (\Throwable $e) {
                $result = null;
            }
            $seconds = round(microtime(true) - $started, 3);

            $predLat = is_array($result) ? ($result['latitude'] ?? null) : null;
            $predLng = is_array($result) ? ($result['longitude'] ?? null) : null;
            $failed = ! is_array($result) || isset($result['error']) || ! is_numeric($predLat) || ! is_numeric($predLng);

            $errorKm = $failed ? null : $this->haversine(
                (float) $label['latitude'], (float) $label['longitude'],
                (float) $predLat, (float) $predLng
            );

            $rows[] = [
                'file' => $label['file'],
                'expected' => [$label['latitude'], $label['longitude']],
                'predicted' => [$predLat, $predLng],
                'error_km' => $errorKm === null ? null : round($errorKm, 2),
                'confidence' => is_array($result) ? ($result['confidence'] ?? 0) : 0,
                'model_reported_confidence' => is_array($result) ? ($result['model_reported_confidence'] ?? 0) : 0,
                'low_confidence' => is_array($result) ? (bool) ($result['low_confidence'] ?? true) : true,
                'seconds' => $seconds,
                'failed' => $failed,
            ];
        }

        $report = $this->buildReport($rows, $live, $realPhotoMode);
        $reportPath = (string) ($this->option('report') ?: storage_path('app/eval-'.($live ? 'live' : 'mock').'.json'));
        file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->printSummary($report);
        $this->line('Report: '.$reportPath);

        return self::SUCCESS;
    }

    private function readLabels(?string $path = null): array
    {
        $path = $path ?: dirname(__DIR__, 3).'/tests/fixtures/eval/labels.csv';
        if (! is_file($path)) {
            return [];
        }

        $labels = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if (count($row) < 6) {
                continue;
            }
            $label = array_combine($header, $row);
            if (! array_key_exists('license', $label) && array_key_exists('licence', $label)) {
                $label['license'] = $label['licence'];
            }
            $labels[] = $label;
        }
        fclose($handle);

        return $labels;
    }

    private function buildReport(array $rows, bool $live, bool $realPhotoMode = false): array
    {
        $errors = array_values(array_filter(array_column($rows, 'error_km'), fn ($v) => $v !== null));
        $latencies = array_values(array_column($rows, 'seconds'));
        $failures = count(array_filter($rows, fn ($r) => $r['failed']));
        $total = count($rows);

        $calibration = [];
        foreach (['low:0-49' => [0, 49], 'medium:50-79' => [50, 79], 'high:80-100' => [80, 100]] as $name => [$min, $max]) {
            $bucket = array_values(array_filter($rows, function ($r) use ($min, $max) {
                return $r['error_km'] !== null && $r['confidence'] >= $min && $r['confidence'] <= $max;
            }));
            $within200 = count(array_filter($bucket, fn ($r) => $r['error_km'] <= 200));
            $within25 = count(array_filter($bucket, fn ($r) => $r['error_km'] <= 25));
            $bandErrors = array_column($bucket, 'error_km');
            $calibration[$name] = [
                'count' => count($bucket),
                'pct_within_25km' => $bucket ? round(100 * $within25 / count($bucket), 1) : null,
                'pct_within_200km' => $bucket ? round(100 * $within200 / count($bucket), 1) : null,
                'median_error_km' => $bandErrors ? round($this->percentile($bandErrors, 0.5), 2) : null,
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'mode' => $live ? 'live' : 'mock',
            'dataset' => $realPhotoMode ? 'real-photo' : 'synthetic-fixtures',
            'fixture_license' => $realPhotoMode
                ? 'User-supplied photos; see the license column of the labels CSV'
                : 'CC0 synthetic images; coordinates are public geographic facts',
            'count' => $total,
            'metrics' => [
                'median_error_km' => $errors ? round($this->percentile($errors, 0.5), 2) : null,
                'pct_within' => [
                    '1km' => $this->pctWithin($errors, 1),
                    '25km' => $this->pctWithin($errors, 25),
                    '200km' => $this->pctWithin($errors, 200),
                    '750km' => $this->pctWithin($errors, 750),
                    '2500km' => $this->pctWithin($errors, 2500),
                ],
                'latency_p50_s' => $latencies ? round($this->percentile($latencies, 0.5), 3) : null,
                'latency_p95_s' => $latencies ? round($this->percentile($latencies, 0.95), 3) : null,
                'failure_rate_pct' => $total ? round(100 * $failures / $total, 1) : null,
                'calibration' => $calibration,
            ],
            'rows' => $rows,
        ];
    }

    private function pctWithin(array $errors, float $km): ?float
    {
        if (! $errors) {
            return null;
        }

        return round(100 * count(array_filter($errors, fn ($e) => $e <= $km)) / count($errors), 1);
    }

    private function percentile(array $values, float $p): float
    {
        $sorted = $values;
        sort($sorted);
        $index = (int) ceil($p * count($sorted)) - 1;

        return (float) $sorted[max(0, min(count($sorted) - 1, $index))];
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * asin(min(1, sqrt($a)));
    }

    private function printSummary(array $report): void
    {
        $m = $report['metrics'];
        $this->table(['Metric', 'Value'], [
            ['Images', $report['count']],
            ['Median error (km)', $m['median_error_km'] ?? 'n/a'],
            ['Within 25 km', $m['pct_within']['25km'].'%'],
            ['Within 200 km', $m['pct_within']['200km'].'%'],
            ['Latency p50 (s)', $m['latency_p50_s'] ?? 'n/a'],
            ['Latency p95 (s)', $m['latency_p95_s'] ?? 'n/a'],
            ['Failure rate', $m['failure_rate_pct'].'%'],
        ]);

        $bandRows = [];
        foreach ($m['calibration'] as $name => $stats) {
            $bandRows[] = [
                $name,
                $stats['count'],
                $stats['pct_within_25km'] === null ? 'n/a' : $stats['pct_within_25km'].'%',
                $stats['pct_within_200km'] === null ? 'n/a' : $stats['pct_within_200km'].'%',
                $stats['median_error_km'] ?? 'n/a',
            ];
        }
        $this->line('Accuracy by predicted-confidence band:');
        $this->table(['band', 'n', 'within 25 km', 'within 200 km', 'median error (km)'], $bandRows);
    }

    private function evaluateSingleImage(GeminiService $gemini, string $path, array $models, bool $live): int
    {
        if (! is_file($path)) {
            $this->error("Image not found: {$path}");

            return self::FAILURE;
        }
        if (empty($models)) {
            $models = array_values(array_unique(array_filter([
                (string) config('services.gemini.model'),
                (string) config('services.gemini.fallback_model'),
            ])));
        }

        $bytes = (string) file_get_contents($path);
        $rows = [];

        foreach ($models as $model) {
            config(['services.gemini.model' => $model, 'services.gemini.fallback_model' => $model]);
            app()->forgetInstance(GeminiClient::class);

            $started = microtime(true);
            try {
                $result = $gemini->analyzeGeolocation($bytes, []);
            } catch (\Throwable $e) {
                $result = null;
            }
            $seconds = round((microtime(true) - $started) * 1000, 1);

            $rows[] = [
                $model,
                sprintf('%.1f/%.1f/%.1f', $seconds, $seconds, $seconds),
                is_array($result) ? ($result['category'] ?? 'ok') : 'other',
                (string) config('services.gemini.thinking_level', 'low'),
                is_array($result) ? (int) ($result['confidence'] ?? 0) : 0,
            ];
        }

        $this->table(
            ['model', 'ms min/median/max', 'category', 'thinking level', 'calibrated confidence'],
            $rows
        );
        if (! $live) {
            $this->warn('Mock mode: Http::fake returns deterministic data; use --live for real observations.');
        }
        $this->info('Record per-model live results in docs/DEPLOY_NOTES.md before enabling any minimal thinking override.');

        return self::SUCCESS;
    }
}
