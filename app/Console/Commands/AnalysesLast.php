<?php

namespace App\Console\Commands;

use App\Models\GeoAnalysis;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Console\Command;

/**
 * Print the last five analyses for local/deploy triage. Never prints keys,
 * image bytes or exception details.
 */
class AnalysesLast extends Command
{
    protected $signature = 'analyses:last';

    protected $description = 'Show the last five analyses with created_at, status and outcome';

    public function handle(): int
    {
        $analyses = GeoAnalysis::query()->latest('id')->limit(5)->get();

        if ($analyses->isEmpty()) {
            $this->info('No analyses found.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($analyses as $analysis) {
            $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
            if (! is_array($result)) {
                $result = [];
            }

            $processing = ($analysis->finished_at && $analysis->started_at)
                ? round(abs($analysis->finished_at->diffInSeconds($analysis->started_at)), 2)
                : null;

            $rows[] = [
                $analysis->id,
                $analysis->created_at?->toIso8601String() ?? 'n/a',
                $analysis->status,
                $analysis->model_used ?: 'unknown',
                $processing ?? 'n/a',
                $analysis->queue_ms ?? 'n/a',
                $analysis->gemini_ms ?? 'n/a',
                $analysis->total_ms ?? 'n/a',
                $result['landmark_name'] ?? 'Unknown',
                $result['confidence'] ?? 'n/a',
                isset($result['low_confidence']) ? ($result['low_confidence'] ? 'true' : 'false') : 'n/a',
                GeminiFailureClassifier::categoryFromStageLabel($analysis->stage_label) ?? 'n/a',
            ];
        }

        $this->table([
            'id', 'created_at', 'status', 'model (winner)', 'processing_seconds',
            'queue_ms', 'gemini_ms', 'total_ms (end-to-end)', 'landmark',
            'confidence', 'low_confidence', 'failure_category',
        ], $rows);

        return self::SUCCESS;
    }
}
