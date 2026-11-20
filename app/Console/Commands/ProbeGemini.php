<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use Illuminate\Console\Command;

/**
 * Probe Gemini models with the real analysis payload shape (tiny PNG,
 * responseSchema, thinking config). Prints only non-secret diagnostics.
 */
class ProbeGemini extends Command
{
    protected $signature = 'gemini:probe
                            {--model=* : Probe only these models (repeatable)}
                            {--models= : Comma-separated model ids (a,b,c)}
                            {--repeat=1 : Repeat each model N times and report min/median/max}
                            {--thinking= : Thinking level override for this experiment}';

    protected $description = 'Probe models with the real payload shape and print status, latency and sanitized errors';

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

        // "minimal" is not a default. It is only allowed when a live 200 for
        // the exact model/level has been recorded in DEPLOY_NOTES.md.
        if ($thinking !== '' && strtolower($thinking) === 'minimal') {
            foreach ($models as $model) {
                if (! $this->minimalRecorded($model)) {
                    $this->error("Refusing --thinking=minimal for {$model}: no live 200 recorded in docs/DEPLOY_NOTES.md.");

                    return self::FAILURE;
                }
            }
        }

        $allOk = true;

        foreach ($models as $model) {
            $samples = [];
            $last = null;

            for ($i = 0; $i < $repeat; $i++) {
                // Fresh result each time: no stale output between models/runs.
                $last = $client->probeModel($model, $thinking !== '' ? $thinking : null);
                $samples[] = (float) $last['seconds'];
            }

            sort($samples);
            $min = $samples[0];
            $max = $samples[count($samples) - 1];
            $median = $samples[intdiv(count($samples), 2)];

            $extras = [];
            if (! $last['ok']) {
                $allOk = false;
                $detail = $last['error_message'] ?? $last['error'] ?? null;
                if ($detail) {
                    $extras[] = 'error='.$detail;
                }
                if ($last['quota_metric'] ?? null) {
                    $extras[] = 'quotaMetric='.$last['quota_metric'];
                }
                if ($last['quota_id'] ?? null) {
                    $extras[] = 'quotaId='.$last['quota_id'];
                }
            }

            $this->line(sprintf(
                '%s | HTTP %s | %.2f/%.2f/%.2fs | %s%s',
                $last['model'],
                $last['status'],
                $min,
                $median,
                $max,
                $last['category'],
                $extras ? ' | '.implode(' | ', $extras) : ''
            ));
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
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
