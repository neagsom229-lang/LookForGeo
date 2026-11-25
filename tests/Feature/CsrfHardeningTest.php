<?php

namespace Tests\Feature;

use App\Http\Middleware\RequireSessionCookieForCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class CsrfHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_worker_never_caches_html_or_token_responses(): void
    {
        $sw = (string) file_get_contents(base_path('public/sw.js'));

        $this->assertStringContainsString("CACHE_NAME = 'tracegeo-v3'", $sw);
        $this->assertStringContainsString('no-store', $sw);
        $this->assertStringContainsString('self.clients.claim()', $sw);
        // Navigations must not be written into the cache at all.
        $this->assertStringNotContainsString('caches.open(CACHE_NAME).then((c) => c.put(request, copy))', $sw);
    }

    public function test_service_worker_is_served_no_cache(): void
    {
        $htaccess = (string) file_get_contents(base_path('public/.htaccess'));

        $this->assertStringContainsString('<Files "sw.js">', $htaccess);
        $this->assertStringContainsString('Header set Cache-Control "no-cache, no-store, must-revalidate"', $htaccess);
    }

    public function test_bootstrap_trusts_forwarded_proxies(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('trustProxies', $bootstrap);
        $this->assertStringContainsString('HEADER_X_FORWARDED_PROTO', $bootstrap);
        $this->assertStringContainsString('HEADER_X_FORWARDED_FOR', $bootstrap);
        $this->assertStringContainsString('HEADER_X_FORWARDED_PORT', $bootstrap);
        $this->assertStringNotContainsString('HEADER_X_FORWARDED_HOST', $bootstrap);
    }

    public function test_csrf_token_endpoint_returns_fresh_token_with_no_store(): void
    {
        $response = $this->withSession(['probe' => 1])->getJson('/csrf-token');
        $response->assertOk();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));
        $this->assertStringNotContainsString('cookie', strtolower((string) $response->getContent()));
    }

    public function test_no_cookie_short_circuits_before_session_starts(): void
    {
        config(['session.driver' => 'database']);
        $this->app->detectEnvironment(fn () => 'production');
        $called = false;

        $response = (new RequireSessionCookieForCsrfToken)->handle(
            Request::create('/csrf-token', 'GET'),
            function () use (&$called) {
                $called = true;

                return response('should not run');
            }
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('NO_SESSION', $response->getData(true)['code']);
        $this->assertFalse($called);
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_csrf_token_endpoint_is_throttled_to_30_per_minute(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->withSession(['probe' => $i])->getJson('/csrf-token')->assertOk();
        }

        $this->withSession(['probe' => 31])->getJson('/csrf-token')->assertStatus(429);
    }

    public function test_pages_embedding_a_csrf_token_are_no_store(): void
    {
        foreach (['/', '/login', '/docs'] as $uri) {
            $response = $this->get($uri)->assertOk();
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    /** A 419 must be diagnosable without leaking tokens, cookies or session ids. */
    public function test_419_diagnostic_log_contains_no_secrets(): void
    {
        config(['app.debug' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        $log = Log::spy();

        $image = imagecreatetruecolor(10, 10);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $response = $this->call('POST', '/api/analyze/store', [
            '_token' => 'stale-token-value',
        ], [], [
            'image' => UploadedFile::fake()->createWithContent('x.png', $bytes),
        ], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => 'stale-token-value',
            'HTTP_USER_AGENT' => 'diag-test-agent',
        ]);

        $response->assertStatus(419);

        $log->shouldHaveReceived('warning')->with(
            'csrf_419_diagnostic',
            Mockery::on(function (array $context) {
                foreach (['request_id', 'route', 'session_cookie_present', 'csrf_header_present',
                    'csrf_input_present', 'session_id_matched_existing', 'user_id', 'user_agent'] as $key) {
                    if (! array_key_exists($key, $context)) {
                        return false;
                    }
                }
                $serialized = json_encode($context);

                return ! str_contains((string) $serialized, 'stale-token-value')
                    && ! str_contains((string) $serialized, 'tracegeo_session=');
            })
        );
    }
}
