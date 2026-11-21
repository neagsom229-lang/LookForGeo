<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use Illuminate\Console\Command;

/**
 * Probe Gemini models with the real analysis payload shape. Prints one table
 * with min/median/max latency, category and thinking level per model.
 */
class ProbeGemini extends Command
{
    protected $signature = 'gemini:probe
                            {--model=* : Probe only these models (repeatable)}
                            {--models= : Comma-separated model ids (a,b,c)}
                            {--image= : Real photo path; resized exactly like the app}
                            {--repeat=1 : Repeat each model N times}
                            {--thinking= : Thinking level override for this experiment}';

    protected $description = 'Probe models with the real payload shape and print per-model latency and category';

    public function handle(GeminiClient $client): int
    {
        $models = (array) $this->option('model');

        if ($csv = (string) $this->option('models')) {
            foreach (explode(',', $csv) as $model) {
                $model = trim($model);
                if ($model !== '') {
                    $models[] = $model;
                }
            }
        }

        $models = array_values(array_unique(array_filter($models)));

        if (empty($models)) {
            $models = array_values(array_unique(array_filter([
                (string) config('services.gemini.model', 'gemini-3.5-flash-lite'),
                (string) config('services.gemini.fallback_model', 'gemini-3.1-flash-lite'),
            ])));
        }

        $repeat = max(1, (int) $this->option('repeat'));
        $thinking = trim((string) $this->option('thinking'));

        if ($thinking !== '' && strtolower($thinking) === 'minimal') {
            foreach ($models as $model) {
                if (! $this->minimalRecorded($model)) {
                    $this->error("Refusing --thinking=minimal for {$model}: no live 200 recorded in docs/DEPLOY_NOTES.md.");

                    return self::FAILURE;
                }
            }
        }

        $imageData = null;
        $imageMime = null;
        if ($imagePath = (string) $this->option('image')) {
            if (! is_file($imagePath)) {
                $this->error("Image not found: {$imagePath}");

                return self::FAILURE;
            }
            $prepared = $client->prepareImageForModel((string) file_get_contents($imagePath));
            $imageData = $prepared['data'];
            $imageMime = $prepared['mime'];
        }

        $rows = [];
        $allOk = true;

        foreach ($models as $model) {
            $samples = [];
            $last = null;

            for ($i = 0; $i < $repeat; $i++) {
                $last = $client->probeModel($model, $thinking !== '' ? $thinking : null, $imageData, $imageMime);
                $samples[] = (float) $last['seconds'];
            }

            sort($samples);
            if (! $last['ok']) {
                $allOk = false;
            }
            $detail = $last['error_message'] ?? $last['error'] ?? null;
            if ($last['quota_metric'] ?? null) {
                $detail = trim((string) $detail.' quotaMetric='.$last['quota_metric']);
            }
            if ($last['quota_id'] ?? null) {
                $detail = trim((string) $detail.' quotaId='.$last['quota_id']);
            }

            $rows[] = [
                $model,
                sprintf('%.2f/%.2f/%.2f', $samples[0], $samples[intdiv(count($samples), 2)], $samples[count($samples) - 1]),
                $last['category'],
                $last['thinking_level'] ?? $this->effectiveThinking($thinking, $client, $model),
                $detail ? substr($detail, 0, 120) : '-',
            ];
        }

        $this->table(['model', 'ms min/median/max', 'category', 'thinking level', 'google error (redacted)'], $rows);

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    private function effectiveThinking(string $thinking, GeminiClient $client, string $model): string
    {
        if ($thinking !== '') {
            return $thinking;
        }

        // Probe result always carries thinking_level; this is only a fallback.
        return 'n/a';
    }

    private function minimalRecorded(string $model): bool
    {
        $path = base_path('docs/DEPLOY_NOTES.md');
        if (! is_file($path)) {
            return false;
        }

        $doc = (string) file_get_contents($path);
        $quoted = preg_quote($model, '/');

        return (bool) preg_match('/200.*'.$quoted.'.*minimal|minimal.*'.$quoted.'.*200/i', $doc);
    }
}
