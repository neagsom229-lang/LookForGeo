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
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Proves a transient-release job runs again with worker --tries=1 because the
 * job-level tries is embedded in the queue payload and overrides worker tries.
 */
class RequeueWorkerTest extends TestCase
{
    use RefreshDatabase;

    /** Spy instance returned by Log::spy(); asserted on directly so static
     *  facade forwarding does not look like an undefined static method. */
    private MockInterface $logSpy;

    public function test_released_job_runs_a_second_time_with_worker_tries_1(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('analyses/local.png', 'image-bytes');
        config([
            'queue.default' => 'database',
            'services.analysis.transient_release_delays' => [0, 0, 0],
            'services.gemini.retries' => 0,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 1,
            'services.gemini.retry_backoff_ms' => 0,
            'services.gemini.hedge_after' => 0,
            'services.gemini.circuit_failures' => 10,
        ]);
        $this->logSpy = Log::spy();

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls <= 2) {
                return Http::response(['error' => 'unavailable'], 503);
            }

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'landmark_name' => 'Eiffel Tower',
                            'city' => 'Paris',
                            'country' => 'France',
                            'latitude' => 48.8584,
                            'longitude' => 2.2945,
                            'confidence' => 80,
                            'reasoning' => 'Tower',
                            'description' => 'Tower',
                        ]),
                    ]]],
                ]],
                'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1, 'totalTokenCount' => 2],
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

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(4, $payload['maxTries']);

        $worker = ['--once' => true, '--tries' => 1, '--timeout' => 180, '--no-interaction' => true];

        $this->assertSame(0, Artisan::call('queue:work', $worker, new BufferedOutput));
        $analysis->refresh();
        $this->assertSame('processing', $analysis->status);
        $this->assertSame('Waiting for AI service', $analysis->stage_label);

        $this->assertSame(0, Artisan::call('queue:work', $worker, new BufferedOutput));
        $analysis->refresh();
        $this->assertSame('completed', $analysis->status);

        $this->logSpy->shouldHaveReceived('info')->with('AnalyzeImageJob attempt', Mockery::on(function ($context) {
            return ($context['attempt'] ?? 0) === 2 && ($context['max_tries'] ?? 0) === 4;
        }));

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
