<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use Illuminate\Console\Command;

/**
 * Probe Gemini models with the real analysis payload shape (tiny PNG,
 * responseSchema, thinking config). Prints only non-secret diagnostics:
 * model, HTTP status, seconds and Google's sanitized error/quota details.
 */
class ProbeGemini extends Command
{
    protected $signature = 'gemini:probe
                            {--model=* : Probe only these models (repeatable)}
                            {--models= : Comma-separated model ids (a,b,c)}';

    protected $description = 'Probe Gemini models with the real payload shape and print status, latency and sanitized errors';

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
                (string) config('services.gemini.model', 'gemini-3.8-flash'),
                (string) config('services.gemini.fallback_model', 'gemini-3.5-flash'),
            ])));
        }

        $allOk = true;

        foreach ($models as $model) {
            // Fresh result per model: no value from a previous model is reused.
            $result = $client->probeModel($model);

            $extras = [];
            if (! $result['ok']) {
                $allOk = false;
                $detail = $result['error_message'] ?? $result['error'] ?? null;
                if ($detail) {
                    $extras[] = 'error='.$detail;
                }
                if ($result['quota_metric'] ?? null) {
                    $extras[] = 'quotaMetric='.$result['quota_metric'];
                }
                if ($result['quota_id'] ?? null) {
                    $extras[] = 'quotaId='.$result['quota_id'];
                }
            }

            $this->line(sprintf(
                '%s | HTTP %s | %.2fs | %s%s',
                $result['model'],
                $result['status'],
                $result['seconds'],
                $result['category'],
                $extras ? ' | '.implode(' | ', $extras) : ''
            ));
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
