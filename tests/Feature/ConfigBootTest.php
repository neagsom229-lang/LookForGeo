<?php

namespace Tests\Feature;

use Tests\TestCase;

class ConfigBootTest extends TestCase
{
    /**
     * Every config file must load without notices/deprecations/warnings.
     * (Regression: PDO::MYSQL_ATTR_SSL_CA printed into every response body.)
     */
    public function test_config_files_load_without_notices(): void
    {
        $messages = [];
        set_error_handler(function (int $severity, string $message, string $file, int $line) use (&$messages) {
            $messages[] = sprintf('[%d] %s (%s:%d)', $severity, $message, $file, $line);

            return true;
        });

        foreach (glob(config_path('*.php')) as $file) {
            require $file;
        }

        restore_error_handler();

        $this->assertSame([], $messages, 'Config load emitted: '.implode(' | ', $messages));
    }

    /**
     * The full application boot (config + service providers) must print
     * nothing — any deprecation leaks into HTTP response bodies and breaks
     * headers (Set-Cookie) and csrf tokens.
     */
    public function test_artisan_boot_emits_no_notices(): void
    {
        $output = shell_exec('php artisan db:show 2>&1');

        $this->assertIsString($output);
        $this->assertStringNotContainsString('Deprecated:', $output);
        $this->assertStringNotContainsString('Warning:', $output);
        $this->assertStringNotContainsString('Notice:', $output);
    }
}
