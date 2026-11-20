<?php

namespace App\Console\Commands;

use App\Services\Gemini\GeminiClient;
use Illuminate\Console\Command;

/**
 * Probe each configured Gemini model with a tiny text request and print
 * only the model name, HTTP status and elapsed seconds. The API key is
 * never printed, so this is safe to call from smoke.ps1 -Live in CI logs.
 */
class ProbeGemini extends Command
{
    protected $signature = 'gemini:probe {--model=* : Probe only these models (repeatable)}';

    protected $description = 'Print HTTP status and latency for each configured Gemini model';

    public function handle(GeminiClient $client): int
    {
        $models = (array) $this->option('model');

        if (empty($models)) {
            $models = array_values(array_unique(array_filter([
                (string) config('services.gemini.model', 'gemini-3.6-flash'),
                (string) config('services.gemini.fallback_model', 'gemini-3.5-flash'),
            ])));
        }

        $allOk = true;

        foreach ($models as $model) {
            $result = $client->probeModel($model);

            $this->line(sprintf(
                '%-28s HTTP %-3s %6.2fs %s',
                $result['model'],
                $result['status'],
                $result['seconds'],
                $result['ok'] ? 'OK' : 'FAIL'
            ));

            if (! $result['ok']) {
                $allOk = false;
                if (isset($result['error'])) {
                    $this->warn('  '.$result['error']);
                }
            }
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }
}
