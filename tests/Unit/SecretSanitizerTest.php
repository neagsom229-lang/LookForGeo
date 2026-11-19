<?php

namespace Tests\Unit;

use App\Services\Gemini\SecretSanitizer;
use PHPUnit\Framework\TestCase;

class SecretSanitizerTest extends TestCase
{
    public function test_redacts_url_query_key_values(): void
    {
        $url = 'https://host/v1beta/models/m:generateContent?key=AIzaSyAbCdEfGhIjKlMnOpQrStUvWxYz123456';

        $out = SecretSanitizer::redact($url);

        $this->assertStringNotContainsString('AIza', $out);
        $this->assertStringContainsString('key=[REDACTED]', $out);
        $this->assertStringStartsWith('https://host/v1beta/models/m:generateContent?key=', $out);
    }

    public function test_redacts_key_in_the_middle_of_a_sentence(): void
    {
        $msg = 'cURL error 28: timed out with url: https://x/y?key=SECRETVALUE123&model=m';

        $out = SecretSanitizer::redact($msg);

        $this->assertStringNotContainsString('SECRETVALUE123', $out);
        $this->assertStringContainsString('key=[REDACTED]&model=m', $out);
    }

    public function test_does_not_mangle_apikey_word_but_redacts_api_key_header_form(): void
    {
        // "apikey=" is a different word and must survive...
        $this->assertStringContainsString('apikey=keepme', SecretSanitizer::redact('apikey=keepme'));
        // ...while the header form "x-goog-api-key=" is a secret carrier.
        $this->assertStringNotContainsString('keepme2', SecretSanitizer::redact('x-goog-api-key=keepme2'));
    }

    public function test_redacts_bare_aiza_keys(): void
    {
        $out = SecretSanitizer::redact('leak: AIzaSyFakeFakeFakeFakeFakeFake12 in message');

        $this->assertStringNotContainsString('AIzaSyFakeFakeFakeFakeFakeFake12', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    public function test_redacts_aq_prefixed_keys(): void
    {
        $out = SecretSanitizer::redact('token AQabcdef123456789 used');

        $this->assertStringNotContainsString('AQabcdef123456789', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }

    public function test_redact_array_is_recursive(): void
    {
        $out = SecretSanitizer::redactArray([
            'plain' => 'ok',
            'url' => 'https://x?k=1&key=AIzaSyFakeFakeFakeFakeFakeFake12',
            'nested' => ['deep' => ['key=SHOULDGO', 'AIzaSyFakeFakeFakeFakeFakeFake12']],
        ]);

        $encoded = json_encode($out);
        $this->assertStringNotContainsString('AIzaSyFakeFakeFakeFakeFakeFake12', $encoded);
        $this->assertStringNotContainsString('SHOULDGO', $encoded);
        $this->assertSame('ok', $out['plain']);
    }

    public function test_null_and_empty_are_safe(): void
    {
        $this->assertSame('', SecretSanitizer::redact(null));
        $this->assertSame('', SecretSanitizer::redact(''));
    }
}
