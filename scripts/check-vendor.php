<?php

// Compare composer.lock with the actually installed vendor tree. A mismatch
// means tests run against stale code; fail closed with the exact remedy.

$root = dirname(__DIR__);
$lockPath = $root.'/composer.lock';
$installedPath = $root.'/vendor/composer/installed.json';

foreach ([$lockPath, $installedPath] as $path) {
    if (! is_file($path)) {
        fwrite(STDERR, 'run composer install (missing '.basename($path).")\n");
        exit(1);
    }
}

$lock = json_decode((string) file_get_contents($lockPath), true);
$installed = json_decode((string) file_get_contents($installedPath), true);
if (! is_array($lock) || ! is_array($installed)) {
    fwrite(STDERR, "run composer install (unreadable lock or installed.json)\n");
    exit(1);
}

function normalizeVersion(string $version): string
{
    $version = ltrim($version, 'v');

    return $version;
}

$want = [];
foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    if (isset($package['name'], $package['version'])) {
        $want[$package['name']] = normalizeVersion((string) $package['version']);
    }
}

$have = [];
foreach ($installed['packages'] ?? [] as $package) {
    if (isset($package['name'], $package['version'])) {
        $have[$package['name']] = normalizeVersion((string) $package['version']);
    }
}

$problems = [];
foreach ($want as $name => $version) {
    if (! array_key_exists($name, $have)) {
        $problems[] = "missing {$name}";
    } elseif ($have[$name] !== $version) {
        $problems[] = "{$name}: lock={$version} vendor={$have[$name]}";
    }
}
foreach ($have as $name => $version) {
    if (! array_key_exists($name, $want)) {
        $problems[] = "extra {$name}";
    }
}

if ($problems !== []) {
    fwrite(STDERR, "run composer install (vendor differs from composer.lock):\n  "
        .implode("\n  ", $problems)."\n");
    exit(1);
}

echo 'vendor in sync with composer.lock ('.count($want)." packages)\n";
exit(0);
