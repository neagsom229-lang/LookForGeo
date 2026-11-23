<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Runs the shell harness that exercises entrypoint.sh's migration loop. */
class EntrypointMigrationTest extends TestCase
{
    public function test_migration_loop_is_bounded_and_reports_the_real_error(): void
    {
        $script = 'tests/shell/entrypoint-migrate.sh';
        $this->assertFileExists(base_path($script));

        $shell = null;
        foreach (['sh', 'bash', 'C:\\Program Files\\Git\\bin\\bash.exe', 'D:\\program file\\Git\\bin\\bash.exe'] as $candidate) {
            $probe = new Process([$candidate, '-c', 'true']);
            $probe->run();
            if ($probe->isSuccessful()) {
                $shell = $candidate;
                break;
            }
        }
        if ($shell === null) {
            $this->markTestSkipped('No POSIX shell available for the entrypoint harness.');
        }

        $process = new Process([$shell, $script], base_path());
        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('ENTRYPOINT_HARNESS_OK', $output);
    }
}
