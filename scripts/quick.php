<?php

// Fast pre-push gate: pint-changed + phpstan on changed PHP paths + Unit tests.
// Target: <15s. Full composer ci remains the CI/manual gate.

$root = dirname(__DIR__);
chdir($root);

function start(array $command, string $cwd): array
{
    $out = tempnam(sys_get_temp_dir(), 'qk-out');
    $err = tempnam(sys_get_temp_dir(), 'qk-err');
    $proc = proc_open($command, [1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']], $pipes, $cwd);
    if (! is_resource($proc)) {
        @unlink($out);
        @unlink($err);

        return ['proc' => null, 'out' => $out, 'err' => $err, 'status' => 1];
    }

    return ['proc' => $proc, 'out' => $out, 'err' => $err, 'status' => null];
}

function finish(array $job): array
{
    $status = $job['proc'] ? proc_close($job['proc']) : (int) $job['status'];
    $out = (string) @file_get_contents($job['out']);
    $err = (string) @file_get_contents($job['err']);
    @unlink($job['out']);
    @unlink($job['err']);

    return [$status, $out.$err];
}

function changedPhpFiles(string $root): array
{
    // Two-dot avoids a multi-merge-base warning from a merge commit.
    $proc = proc_open(['git', 'diff', '--name-only', '--diff-filter=AM', 'origin/main..HEAD', '--', 'app', 'routes', 'config', 'database', 'tests', 'scripts'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (! is_resource($proc)) {
        fwrite(STDERR, "quick: git diff failed\n");
        exit(1);
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($proc) !== 0) {
        fwrite(STDERR, "quick: git diff failed: {$out}{$err}\n");
        exit(1);
    }

    return array_values(array_filter(preg_split('/\R/', $out) ?: [], fn ($f) => str_ends_with($f, '.php') && is_file($root.'/'.$f)));
}

$started = microtime(true);
$files = changedPhpFiles($root);

$jobs = [];
$jobs['pint-changed'] = start([PHP_BINARY, 'scripts/pint-changed.php'], $root);
if ($files !== []) {
    $jobs['phpstan (changed files)'] = start(array_merge([PHP_BINARY, 'vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G'], $files), $root);
} else {
    echo "quick: phpstan skipped (no changed PHP files)\n";
}
$jobs['unit tests'] = start([PHP_BINARY, 'vendor/bin/phpunit', '--testsuite=Unit', '--stop-on-failure'], $root);

$failed = false;
foreach ($jobs as $label => $job) {
    [$status, $out] = finish($job);
    if ($status !== 0) {
        fwrite(STDERR, $out);
        fwrite(STDERR, "quick: {$label} FAILED (exit {$status})\n");
        $failed = true;
    } else {
        echo "quick: {$label} ok\n";
    }
}

if ($failed) {
    exit(1);
}

printf("quick: passed in %.2fs\n", microtime(true) - $started);
