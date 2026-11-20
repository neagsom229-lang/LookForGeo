<?php

// Hermetic proof: copy HEAD into a temporary worktree, place a .env with
// deliberately contradicting dummy values, and run the full suite there.
// phpunit.xml uses force="true" so those decoys must be ignored.
// This script NEVER reads the developer's real .env.

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir().'/tg-decoy-'.bin2hex(random_bytes(5));

function runCommand(array $command, string $cwd): array
{
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $cwd);

    if (! is_resource($process)) {
        return [1, 'could not start process'];
    }

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), trim($out."\n".$err)];
}

$decoy = [
    'APP_ENV' => 'local',
    'APP_KEY' => 'base64:JnjDNND9BBQ0AcXMlfrBO2rYuAwFl0N0kobr8iqLFJA=',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DATABASE_URL' => 'sqlite:///:memory:',
    'GEMINI_API_KEY' => 'decoy-key-not-a-real-secret',
    'GEMINI_MODEL' => 'zzz',
    'GEMINI_FALLBACK_MODEL' => 'yyy',
    'GEMINI_ESCALATION_MODEL' => 'x',
    'CACHE_STORE' => 'file',
    'QUEUE_CONNECTION' => 'database',
    'SESSION_DRIVER' => 'database',
    'LOG_CHANNEL' => 'single',
    'CLOUDINARY_URL' => 'cloudinary://decoy:decoy@decoy',
];

try {
    [$status, $out] = runCommand(['git', 'worktree', 'add', '--detach', $tmp, 'HEAD'], $root);
    if ($status !== 0) {
        fwrite(STDERR, "worktree add failed: {$out}\n");
        exit(1);
    }

    if (PHP_OS_FAMILY === 'Windows') {
        $linkCmd = sprintf('cmd /c mklink /J "%s" "%s" 2>&1', $tmp.'\\vendor', $root.'\\vendor');
        shell_exec($linkCmd);
    } else {
        @symlink($root.'/vendor', $tmp.'/vendor');
    }

    if (! is_file($tmp.'/vendor/autoload.php')) {
        fwrite(STDERR, "vendor link was not created in the decoy worktree\n");
        exit(1);
    }

    foreach ([
        'bootstrap/cache',
        'storage/framework/views',
        'storage/framework/sessions',
        'storage/framework/cache/data',
        'storage/logs',
        'storage/app',
    ] as $dir) {
        @mkdir($tmp.'/'.$dir, 0777, true);
    }

    $env = '';
    foreach ($decoy as $key => $value) {
        $env .= $key.'='.$value."\n";
    }
    file_put_contents($tmp.'/.env', $env);

    echo 'DECOY .env keys: '.implode(',', array_keys($decoy))."\n";
    echo "Running full suite with decoy values (phpunit.xml force=true must win)...\n";

    [$testStatus, $testOut] = runCommand(['php', 'vendor/bin/phpunit'], $tmp);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $testOut))));
    $summary = array_values(array_filter($lines, function ($line) {
        return preg_match('/^(OK|FAILURES|ERRORS|Tests:|[0-9]+\)|Failed asserting|--- Expected|\+\+\+ Actual|@@)/', $line) === 1;
    }));
    foreach (($summary ?: array_slice($lines, -12)) as $line) {
        echo $line."\n";
    }

    if ($testStatus !== 0) {
        fwrite(STDERR, "DECOY TEST FAILED (exit {$testStatus})\n");
    } else {
        echo "DECOY TEST PASSED: contradicting .env values were ignored.\n";
    }

    $exit = $testStatus;
} finally {
    @unlink($tmp.'/.env');
    runCommand(['git', 'worktree', 'remove', '--force', $tmp], $root);
    if (is_dir($tmp)) {
        // Best-effort removal of leftovers (junction target is never deleted).
        @array_map('unlink', glob($tmp.'/*.*') ?: []);
        @rmdir($tmp);
    }
}

exit($exit ?? 1);
