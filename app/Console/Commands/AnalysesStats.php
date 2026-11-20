<?php

namespace App\Console\Commands;

use App\Models\GeoAnalysis;
use Illuminate\Console\Command;

/**
 * Operational stats from the analyses table. No keys, prompts or image data.
 */
class AnalysesStats extends Command
{
    protected $signature = 'analyses:stats {--hours=24 : Window size in hours}';

    protected $description = 'Report per-model count, latency percentiles, failure rate and hedge win rate';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $analyses = GeoAnalysis::where('created_at', '>=', now()->subHours($hours))->get();

        if ($analyses->isEmpty()) {
            $this->info("No analyses in the last {$hours} hours.");

            return self::SUCCESS;
        }

        $byModel = [];
        $categories = [];
        $hedged = 0;
        $hedgeWins = 0;

        foreach ($analyses as $analysis) {
            $model = $analysis->model_used ?: 'unknown';
            $byModel[$model][] = $analysis->processing_ms;

            if ($analysis->status === 'failed') {
                $category = $analysis->failure_category ?: 'other';
                $categories[$category] = ($categories[$category] ?? 0) + 1;
            }

            $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
            if (is_array($result) && isset($result['hedged'])) {
                $hedged++;
                if (($result['hedge_winner'] ?? null) === (string) config('services.gemini.fallback_model')) {
                    $hedgeWins++;
                }
            }
        }

        $rows = [];
        foreach ($byModel as $model => $samples) {
            $values = array_values(array_filter($samples, fn ($v) => $v !== null));
            sort($values);
            $count = count($values);
            $p50 = $count ? $values[intdiv($count, 2)] : null;
            $p95 = $count ? $values[max(0, (int) ceil(0.95 * $count) - 1)] : null;
            $rows[] = [
                $model,
                count($samples),
                $p50 === null ? 'n/a' : $p50.' ms',
                $p95 === null ? 'n/a' : $p95.' ms',
            ];
        }
        $this->line("Window: last {$hours} hours, analyses=".$analyses->count());
        $this->table(['model', 'count', 'p50 processing', 'p95 processing'], $rows);

        $failed = $analyses->where('status', 'failed')->count();
        $failureRows = [];
        foreach ($categories as $category => $count) {
            $failureRows[] = [$category, $count, round(100 * $count / max(1, $analyses->count()), 1).'%'];
        }
        $this->line("Failed: {$failed} (".round(100 * $failed / max(1, $analyses->count()), 1).'%)');
        $this->table(['failure_category', 'count', 'share of all'], $failureRows);

        $this->line('Hedged: '.$hedged.', hedge wins: '.$hedgeWins.', win rate: '
            .($hedged ? round(100 * $hedgeWins / $hedged, 1).'%' : 'n/a'));

        return self::SUCCESS;
    }
}
