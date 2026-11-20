<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeImageJob;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiClient;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * SECURITY: the Gemini key must never appear in the request URL, any log
 * file, the GeoAnalysis.error column or any API JSON response -- even when
 * a transport exception embeds a URL that contains it.
 */
class SecretLeakTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'AIzaSyFakeTestKeyForLeakCheck123456789';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => self::KEY,
            // Keep the failure path instant (no backoff sleeps) for the test.
            'services.gemini.retries' => 0,
            'services.gemini.max_total_time' => 10,
            // Production refuses to start a request with <15s left; this test
            // uses a 10s budget purely to avoid real waits, so lower the
            // window for the test only.
            'services.gemini.min_attempt_seconds' => 1,
        ]);
    }

    private function timeoutException(): ConnectionException
    {
        return new ConnectionException(
            'cURL error 28: Operation timed out after 30001 milliseconds with url: '
            .'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent'
            .'?key='.self::KEY
        );
    }

    /**
     * Full pipeline: a cURL timeout whose message contains a URL with the
     * key must leave NO key material in the log file, the DB error column
     * or the /api/analyze/{id}/status JSON (which must be generic anyway).
     */
    public function test_key_never_reaches_log_db_or_status_json(): void
    {
        $logFile = storage_path('logs/laravel-test.log');
        $offset = file_exists($logFile) ? (int) filesize($logFile) : 0;

        Http::fake(function () {
            throw $this->timeoutException();
        });

        Storage::fake('public');
        $img = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        imagedestroy($img);
        Storage::disk('public')->put('analyses/leak.png', $png);

        $user = User::factory()->create();
        $analysis = GeoAnalysis::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'stage' => 0,
            'stage_label' => 'Queued',
            'progress' => 10,
            'image_path' => 'analyses/leak.png',
        ]);

        // Isolate the terminal-failure sanitization path; the one automatic
        // requeue for overload/timeout is covered in AutoRequeueTest.
        config(['services.analysis.auto_requeue' => false]);
        AnalyzeImageJob::dispatch($analysis->id, null, 'leak.png');

        // --- DB error column: sanitized, no key ---
        $analysis->refresh();
        $this->assertSame('failed', $analysis->status);
        $this->assertNotNull($analysis->error);
        $this->assertStringNotContainsString(self::KEY, $analysis->error);
        $this->assertStringNotContainsString('AIza', $analysis->error);
        // AI failures now store only the classified generic message.
        $this->assertTrue(GeminiFailureClassifier::isSafeUserMessage($analysis->error));

        // --- API JSON: generic user-facing text, no key anywhere ---
        $response = $this->actingAs($user, 'web')->getJson("/api/analyze/{$analysis->id}/status");
        $response->assertOk();
        $this->assertSame('failed', $response->json('status'));
        $this->assertSame('The AI service did not respond. Please retry.', $response->json('error'));
        $this->assertStringNotContainsString(self::KEY, $response->getContent());

        // --- Prove the central log sanitizer runs on raw messages AND context ---
        Log::error('probe: raw secret key='.self::KEY, [
            'nested' => ['url' => 'https://x/y?key='.self::KEY],
        ]);

        $this->assertFileExists($logFile);
        $written = (string) substr((string) file_get_contents($logFile), $offset);

        $this->assertNotSame('', trim($written), 'expected the test to have written log entries');
        $this->assertStringNotContainsString(self::KEY, $written);
        $this->assertStringNotContainsString('AIza', $written);
        // the probe was logged -- but only in redacted form:
        $this->assertStringContainsString('probe: raw secret key=[REDACTED]', $written);
        $this->assertStringContainsString('key=[REDACTED]', $written);
    }

    /**
     * The key travels in the x-goog-api-key header only -- never in a URL.
     */
    public function test_key_sent_via_header_never_in_url(): void
    {
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"landmark_name":"X"}']]]]],
        ], 200)]);

        $client = app(GeminiClient::class);
        $client->analyzeWithFallback('binary-image-bytes', 'prompt');
        $client->generateText('hello');

        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return ! str_contains($request->url(), 'key=')
                && ! str_contains($request->url(), self::KEY)
                && $request->hasHeader('x-goog-api-key', self::KEY);
        });
    }

    /**
     * Exceptions thrown by GeminiClient are sanitized too (chat endpoint).
     */
    public function test_thrown_client_exceptions_carry_no_key(): void
    {
        Http::fake(function () {
            throw $this->timeoutException();
        });

        $client = app(GeminiClient::class);

        try {
            $client->generateText('hello');
            $this->fail('expected a RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
            $this->assertStringNotContainsString('AIza', $e->getMessage());
            $this->assertStringContainsString('Gemini request failed', $e->getMessage());
        }
    }
}
