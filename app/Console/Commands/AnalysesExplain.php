<?php

namespace App\Console\Commands;

use App\Models\GeoAnalysis;
use App\Services\Gemini\SecretSanitizer;
use Illuminate\Console\Command;

/**
 * Explain a stored confidence score from the signals/factors already saved
 * in the analysis result. Never prints keys, image bytes or raw provider
 * details - only the redacted, classified fields the UI already shows.
 */
class AnalysesExplain extends Command
{
    protected $signature = 'analyses:explain {id : Analysis id}';

    protected $description = 'Explain a stored confidence score from its signals and factors';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $analysis = GeoAnalysis::find($id);

        if (! $analysis) {
            $this->error("Analysis {$id} not found.");

            return self::FAILURE;
        }

        $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
        if (! is_array($result)) {
            $this->error("Analysis {$id} has no stored result to explain.");

            return self::FAILURE;
        }

        $signals = $result['confidence_signals'] ?? [];
        if (! is_array($signals) || $signals === []) {
            $this->line('No confidence_signals stored for this analysis (older result or no scoring pass).');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Analysis %d: confidence=%s level=%s low_confidence=%s basis=%s',
            $id,
            $result['confidence'] ?? 'n/a',
            $result['confidence_level'] ?? 'n/a',
            isset($result['low_confidence']) ? ($result['low_confidence'] ? 'true' : 'false') : 'n/a',
            $result['confidence_basis'] ?? 'n/a'
        ));

        $rows = [];
        foreach ($signals as $name => $signal) {
            if (! is_array($signal)) {
                $signal = ['points' => $signal];
            }
            $details = array_diff_key($signal, array_flip(['points', 'state']));
            $rows[] = [
                $name,
                $signal['points'] ?? 'n/a',
                $signal['state'] ?? '-',
                SecretSanitizer::redact(json_encode($details, JSON_UNESCAPED_SLASHES) ?: '{}'),
            ];
        }
        $this->table(['signal', 'points', 'state', 'details'], $rows);

        $total = array_sum(array_column($signals, 'points'));
        $this->line('Sum of signal points: '.$total.' (stored confidence: '.($result['confidence'] ?? 'n/a').')');

        $factors = $result['confidence_factors'] ?? [];
        $this->line('Factors:');
        if (is_array($factors) && $factors !== []) {
            foreach ($factors as $factor) {
                $this->line('  - '.SecretSanitizer::redact((string) $factor));
            }
        } else {
            $this->line('  (none stored)');
        }

        // Zero score with a consistent answer is the case operators ask
        // about most; explain it without guessing.
        if ((int) ($result['confidence'] ?? -1) === 0) {
            $this->warn('Score is 0: negative signals cancelled the positive ones. See DEPLOY_NOTES.md for the proposed weight changes (not applied).');
        }

        return self::SUCCESS;
    }
}
