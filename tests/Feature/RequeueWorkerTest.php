<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Proves the automatic requeue works with the REAL worker flags used by
 * dev.ps1 (`--tries=1 --timeout=180`): the job's own tries=2 is embedded in
 * the queue payload and overrides --tries=1, so the released job runs again
 * instead of being killed with MaxAttemptsExceededException.
 */
class RequeueWorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_released_job_runs_a_second_time_with_worker_tries_1(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/local.png', 'image-bytes');
        config([
            'queue.default' => 'database',
            'services.analysis.requeue_delay_seconds' => 0,
            'services.gemini.retries' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.hedge_after' => 0,
        ]);
        Log::spy();

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                // First attempt: both models overloaded -> one requeue.
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'confidence' => 80,
                            'reasoning' => 'Tower',
                            'description' => 'Tower',
                        ]),
                    ]]],
                ]],
            ], 200);
        });

        $analysis = GeoAnalysis::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Queued',
            'progress' => 10,
            'image_path' => 'analyses/local.png',
            'image_url' => '/storage/analyses/local.png',
        ]);
        AnalyzeImageJob::dispatch($analysis->id, null, 'local.png');

        // Job-level tries=2 must be baked into the payload; this is what
        // overrides `--tries=1` in the worker.
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(2, $payload['maxTries']);

        $worker = ['--once' => true, '--tries' => 1, '--timeout' => 180, '--no-interaction' => true];

        // First worker pass: overload -> release(0) with stage "Retrying".
        $this->assertSame(0, Artisan::call('queue:work', $worker, new BufferedOutput));
        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame('Retrying', $analysis->stage_label);

        // Second worker pass: the released job actually runs and completes.
        $this->assertSame(0, Artisan::call('queue:work', $worker, new BufferedOutput));
        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);

        Log::shouldHaveReceived('info')->with('AnalyzeImageJob attempt', Mockery::on(function ($context) {
            return ($context['attempt'] ?? 0) === 2 && ($context['max_tries'] ?? 0) === 2;
        }));

        // MaxAttemptsExceededException would send the job to failed_jobs.
        // An empty failed_jobs table proves the released job was allowed to
        // run its second attempt instead of being killed by --tries=1.
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
