<?php

// Mirrors the CI `pint` job exactly: check only files changed between
// origin/main and HEAD in the paths CI watches. No keys, no .env reads.

$root = dirname(__DIR__);
chdir($root);

function run(array $command, string $cwd): array
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

[$status, $changed] = run(
    ['git', 'diff', '--name-only', '--diff-filter=AM', 'origin/main...HEAD', '--', 'app', 'routes', 'config', 'database', 'tests'],
    $root
);

if ($status !== 0) {
    fwrite(STDERR, "pint-changed: git diff failed: {$changed}\n");
    exit(1);
}

$files = array_values(array_filter(preg_split('/\R/', $changed) ?: []));
if ($files === []) {
    echo "No PHP files changed - nothing to check.\n";
    exit(0);
}

echo 'Checking '.count($files)." files\n";
[$pintStatus, $pintOut] = run(array_merge(['php', 'vendor/bin/pint', '--test'], $files), $root);
echo $pintOut."\n";
exit($pintStatus);
