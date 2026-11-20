<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use Illuminate\Console\Command;

/**
 * Recover analyses that were left in `processing` when a queue worker died
 * or a job was killed mid-flight (e.g. `composer dev` reaching its 300s
 * process timeout). Without this, those rows stay "processing" forever and
 * the user sees no failure and no Retry button.
 */
class ReapStuckAnalyses extends Command
{
    protected $signature = 'analyses:reap-stuck
                            {--minutes= : Consider processing older than this stuck (default: config)}
                            {--requeue : Re-queue stuck jobs instead of failing them}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Fail (or re-queue) analyses stuck in processing for too long';

    public function handle(): int
    {
        $raw = $this->option('minutes');
        $minutes = max(1, (int) ($raw !== null && $raw !== '' ? $raw : config('services.analysis.reap_stuck_minutes', 10)));
        $cutoff = now()->subMinutes($minutes);
        $dryRun = (bool) $this->option('dry-run');
        $requeue = (bool) $this->option('requeue');

        $query = GeoAnalysis::query()
            ->where('status', 'processing')
            ->where(function ($q) use ($cutoff) {
                $q->where('started_at', '<=', $cutoff)
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('started_at')->where('created_at', '<=', $cutoff);
                    });
            });

        $count = (clone $query)->count();

        if ($dryRun) {
            $verb = $requeue ? 're-queue' : 'fail';
            $this->info("Would {$verb} {$count} stuck analysis(ies) older than {$minutes} minutes (dry run).");

            return self::SUCCESS;
        }

        $changed = 0;

        $query->orderBy('id')->chunkById(100, function ($analyses) use ($requeue, &$changed) {
            foreach ($analyses as $analysis) {
                if ($requeue) {
                    $analysis->update([
                        'status' => 'pending',
                        'stage' => 0,
                        'stage_label' => 'Re-queued',
                        'progress' => 10,
                        'error' => null,
                        'finished_at' => null,
                        'started_at' => now(),
                    ]);
                    AnalyzeImageJob::dispatch($analysis->id);
                } else {
                    $analysis->update([
                        'status' => 'failed',
                        'stage_label' => 'Timed out',
                        'error' => 'Analysis timed out while processing. Please retry.',
                        'finished_at' => now(),
                    ]);
                }

                $changed++;
            }
        });

        $action = $requeue ? 're-queued' : 'failed';
        $this->info("{$action} {$changed} stuck analysis(ies) older than {$minutes} minutes.");

        return self::SUCCESS;
    }
}
