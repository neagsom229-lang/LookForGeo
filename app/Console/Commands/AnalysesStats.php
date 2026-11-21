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
        $abandoned = 0;
        $abandonedMs = [];
        $promptTokens = [];
        $outputTokens = [];

        foreach ($analyses as $analysis) {
            // Failures have no answering model and no meaningful latency;
            // report them separately below instead of polluting p50/p95.
            if ($analysis->status === 'failed') {
                $category = $analysis->failure_category ?: 'other';
                $categories[$category] = ($categories[$category] ?? 0) + 1;

                continue;
            }

            $model = $analysis->model_used ?: 'unknown';
            $byModel[$model][] = $analysis->processing_ms;

            $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
            if (! is_array($result)) {
                continue;
            }

            if (isset($result['prompt_tokens'])) {
                $promptTokens[] = (int) $result['prompt_tokens'];
            }
            if (isset($result['output_tokens'])) {
                $outputTokens[] = (int) $result['output_tokens'];
            }
            if (isset($result['abandoned_primary'])) {
                $abandoned++;
                if (isset($result['primary_abandoned_ms'])) {
                    $abandonedMs[] = (int) $result['primary_abandoned_ms'];
                }
            }
            if (isset($result['hedged'])) {
                $hedged++;
                $answeredBy = $result['winner'] ?? $result['hedge_winner'] ?? null;
                if ($answeredBy === (string) config('services.gemini.fallback_model')) {
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

        $timingRows = [];
        foreach (['queue_ms' => 'queue', 'gemini_ms' => 'gemini', 'total_ms' => 'total (end-to-end)'] as $field => $label) {
            $values = array_values(array_filter($analyses->pluck($field)->all(), fn ($v) => $v !== null));
            sort($values);
            $count = count($values);
            $timingRows[] = [
                $label,
                $count ? $values[intdiv($count, 2)].' ms' : 'n/a',
                $count ? $values[max(0, (int) ceil(0.95 * $count) - 1)].' ms' : 'n/a',
            ];
        }
        $this->table(['phase', 'p50', 'p95'], $timingRows);

        $failed = $analyses->where('status', 'failed')->count();
        $failureRows = [];
        foreach ($categories as $category => $count) {
            $failureRows[] = [$category, $count, round(100 * $count / max(1, $analyses->count()), 1).'%'];
        }
        $this->line("Failed: {$failed} (".round(100 * $failed / max(1, $analyses->count()), 1).'%)');
        $this->table(['failure_category', 'count', 'share of all'], $failureRows);

        sort($promptTokens);
        sort($outputTokens);
        if ($promptTokens) {
            $p50In = $promptTokens[intdiv(count($promptTokens), 2)];
            $p50Out = $outputTokens ? $outputTokens[intdiv(count($outputTokens), 2)] : 0;
            $share = ($p50In + $p50Out) ? round(100 * $p50Out / ($p50In + $p50Out), 1) : 0;
            $this->line("Tokens p50: prompt={$p50In} output={$p50Out} output_share={$share}%");
        }

        sort($abandonedMs);
        $abCount = count($abandonedMs);
        $abandonedSummary = $abCount
            ? 'avg '.round(array_sum($abandonedMs) / $abCount).' ms, p50 '.$abandonedMs[intdiv($abCount, 2)].' ms, p95 '.$abandonedMs[max(0, (int) ceil(0.95 * $abCount) - 1)].' ms'
            : 'n/a';
        $this->line("Abandoned primaries: {$abandoned}, elapsed at abandonment: {$abandonedSummary}");

        $this->line('Hedged: '.$hedged.', hedge wins: '.$hedgeWins.', win rate: '
            .($hedged ? round(100 * $hedgeWins / $hedged, 1).'%' : 'n/a'));

        return self::SUCCESS;
    }
}
