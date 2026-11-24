<?php

namespace Tests\Feature;

use App\Http\Controllers\AnalysisController;
use App\Models\GeoAnalysis;
use App\Models\User;
use App\Services\Gemini\GeminiFailureClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A failed live attempt must produce BOTH a clear user-facing message and a
 * redacted per-attempt log line carrying model / status or exception class /
 * elapsed ms / thinking level / image bytes.
 */
class GeminiFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.gemini.key' => 'AIzaSyFakeDiagKey1234567890',
            'services.gemini.timeout' => 5,
            'services.gemini.max_total_time' => 20,
            'services.gemini.min_attempt_seconds' => 15,
            'services.gemini.retries' => 0,
            'services.gemini.retry_backoff_ms' => 0,
            // Isolate the terminal-failure sanitization path; the one
            // automatic requeue is covered in AutoRequeueTest.
            'services.analysis.auto_requeue' => false,
        ]);
        Log::spy();
    }

    private function runPipeline(): array
    {
        $user = User::factory()->create();

        $upload = $this->actingAs($user, 'web')->postJson('/api/analyze/store', [
            'image' => UploadedFile::fake()->createWithContent('x.png', $this->pngBytes()),
        ]);
        $upload->assertOk();
        $id = $upload->json('id');

        $status = $this->actingAs($user, 'web')->getJson("/api/analyze/{$id}/status");
        $status->assertOk();

        return ['status' => $status, 'id' => $id];
    }

    public function test_timeout_produces_generic_user_message_and_redacted_log_line(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL 28 timeout url https://x/y?key=AIzaSyFakeDiagKey1234567890');
        });

        $result = $this->runPipeline();

        // Transient timeout is released for retry; final-attempt failure is
        // covered by the job releasing until attempts are exhausted.
        $result['status']->assertJsonPath('status', 'processing')
            ->assertJsonPath('stage_label', 'Waiting for AI service');
        $this->assertAttemptLog(function (array $context) {
            return ($context['exception_class'] ?? null) === ConnectionException::class
                && $context['model'] === 'gemini-3.5-flash-lite'
                && $context['thinking_level'] === 'low'
                && $context['image_bytes'] > 0
                && isset($context['elapsed_ms']);
        });
    }

    public function test_503_produces_generic_user_message_and_status_log_line(): void
    {
        Http::fake(['*' => Http::response(['error' => 'unavailable'], 503)]);

        $result = $this->runPipeline();

        $result['status']->assertJsonPath('status', 'processing')
            ->assertJsonPath('stage_label', 'Waiting for AI service');
        $this->assertAttemptLog(function (array $context) {
            return ($context['http_status'] ?? null) === 503
                && $context['model'] === 'gemini-3.5-flash-lite'
                && $context['thinking_level'] === 'low'
                && $context['image_bytes'] > 0;
        });
    }

    public function test_429_reaches_status_with_quota_message_and_stage_label(): void
    {
        Http::fake(['*' => Http::response(['error' => 'quota'], 429, ['Retry-After' => '5'])]);

        $result = $this->runPipeline();

        // Transient quota is released for retry on non-final attempt.
        $result['status']->assertJsonPath('status', 'processing')
            ->assertJsonPath('stage_label', 'Waiting for AI service');
        $analysis = GeoAnalysis::find($result['id']);
        $this->assertNull($analysis->finished_at);
        $this->assertNull($analysis->model_used);
    }

    public function test_400_schema_or_thinking_rejection_is_logged_with_status(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Invalid generationConfig.thinkingConfig.thinkingLevel'],
        ], 400)]);

        $result = $this->runPipeline();

        $result['status']->assertJsonPath('status', 'failed')
            ->assertJsonPath('error', GeminiFailureClassifier::MESSAGES['bad_request'])
            ->assertJsonPath('failure_category', 'bad_request');
        $this->assertAttemptLog(function (array $context) {
            return ($context['http_status'] ?? null) === 400
                && $context['thinking_level'] === 'low'
                && $context['image_bytes'] > 0;
        });
        // The secret-looking key must never appear in any captured log line.
        Log::shouldHaveReceived('info')
            ->withArgs(function ($message, $context = []) {
                return ! str_contains(json_encode([$message, $context]), 'AIzaSyFakeDiagKey1234567890');
            });
    }

    private function assertAttemptLog(callable $predicate): void
    {
        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($predicate) {
            return $message === 'Gemini attempt'
                && is_array($context)
                && $predicate($context)
                && ! str_contains(json_encode($context), 'AIza');
        });
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
