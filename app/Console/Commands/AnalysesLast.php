<?php

namespace App\Console\Commands;

use App\Models\GeoAnalysis;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Console\Command;

/**
 * Print the most recent analysis for local/deploy triage. Never prints keys,
 * image bytes or exception details.
 */
class AnalysesLast extends Command
{
    protected $signature = 'analyses:last';

    protected $description = 'Show the latest analysis id, status, model, timing and confidence summary';

    public function handle(): int
    {
        $analysis = GeoAnalysis::query()->latest('id')->first();

        if (! $analysis) {
            $this->info('No analyses found.');

            return self::SUCCESS;
        }

        $result = is_string($analysis->result) ? json_decode($analysis->result, true) : $analysis->result;
        if (! is_array($result)) {
            $result = [];
        }

        $processing = ($analysis->finished_at && $analysis->started_at)
            ? round(abs($analysis->finished_at->diffInSeconds($analysis->started_at)), 2)
            : null;

        $rows = [
            ['id', $analysis->id],
            ['status', $analysis->status],
            ['model', (string) config('services.gemini.model')],
            ['processing_seconds', $processing ?? 'n/a'],
            ['queue_ms', $analysis->queue_ms ?? 'n/a'],
            ['gemini_ms', $analysis->gemini_ms ?? 'n/a'],
            ['total_ms (end-to-end)', $analysis->total_ms ?? 'n/a'],
            ['landmark', $result['landmark_name'] ?? 'Unknown'],
            ['confidence', $result['confidence'] ?? 'n/a'],
            ['low_confidence', isset($result['low_confidence']) ? ($result['low_confidence'] ? 'true' : 'false') : 'n/a'],
            ['failure_category', GeminiFailureClassifier::categoryFromStageLabel($analysis->stage_label) ?? 'n/a'],
        ];

        $this->table(['field', 'value'], $rows);

        return self::SUCCESS;
    }
}
