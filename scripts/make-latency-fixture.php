<?php

// Generate a CC0 photographic-noise JPEG fixture for the latency harness.
// Usage: php scripts/make-latency-fixture.php <output.jpg> <small|medium|large> <seed>
//
// Prefers GD's JPEG encoder; falls back to ffmpeg's lavfi noise source when
// GD was built without JPEG support (common on minimal PHP installs).

$dest = $argv[1] ?? null;
$variant = $argv[2] ?? 'small';
$seed = $argv[3] ?? 'latency';

if (! $dest) {
    fwrite(STDERR, "Usage: php scripts/make-latency-fixture.php <output.jpg> <small|medium|large> <seed>\n");
    exit(1);
}

// width, height, ffmpeg q:v, target bytes (all fixtures land in 100-400 KB).
$sizes = [
    'small' => [480, 360, 3, 120000],
    'medium' => [800, 600, 5, 240000],
    'large' => [1200, 900, 14, 380000],
];
if (! isset($sizes[$variant])) {
    fwrite(STDERR, "Unknown variant: {$variant}\n");
    exit(1);
}

[$width, $height, $quality, $target] = $sizes[$variant];
$numericSeed = abs(crc32($variant.'|'.$seed)) % 100000;

if (function_exists('imagejpeg')) {
    mt_srand($numericSeed);
    $image = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y += 3) {
        $baseR = mt_rand(40, 200);
        $baseG = mt_rand(40, 200);
        $baseB = mt_rand(40, 200);
        for ($x = 0; $x < $width; $x += 3) {
            $color = imagecolorallocate(
                $image,
                max(0, min(255, $baseR + mt_rand(-18, 18))),
                max(0, min(255, $baseG + mt_rand(-18, 18))),
                max(0, min(255, $baseB + mt_rand(-18, 18)))
            );
            imagefilledrectangle($image, $x, $y, min($x + 2, $width - 1), min($y + 2, $height - 1), $color);
        }
    }
    $best = null;
    for ($q = 92; $q >= 45; $q -= 3) {
        ob_start();
        imagejpeg($image, null, $q);
        $bytes = (string) ob_get_clean();
        if ($best === null || abs(strlen($bytes) - $target) < abs(strlen($best) - $target)) {
            $best = $bytes;
        }
        if (strlen($bytes) >= 100000 && strlen($bytes) <= 400000) {
            break;
        }
    }
    file_put_contents($dest, $best);
    echo $variant.' '.strlen($best)." bytes (gd)\n";

    exit(0);
}

$ffmpeg = '';
if (PHP_OS_FAMILY === 'Windows') {
    foreach (preg_split('/\r?\n/', (string) shell_exec('where ffmpeg 2>nul')) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && is_file($candidate)) {
            $ffmpeg = $candidate;

            break;
        }
    }
} else {
    $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
}

if ($ffmpeg === '') {
    fwrite(STDERR, "GD has no JPEG encoder and ffmpeg was not found; install one to generate JPEG fixtures.\n");
    exit(1);
}

$filter = sprintf(
    "nullsrc=s=%dx%d,geq=r='random(%d)*255':g='random(%d)*255':b='random(%d)*255'",
    $width,
    $height,
    $numericSeed,
    ($numericSeed + 1000) % 100000,
    ($numericSeed + 2000) % 100000
);
// Use the resolved ffmpeg path so it works on Windows and Linux.
$command = sprintf(
    '%s -y -loglevel error -f lavfi -i %s -frames:v 1 -q:v %d %s 2>&1',
    escapeshellarg($ffmpeg),
    escapeshellarg($filter),
    $quality,
    escapeshellarg($dest)
);
$output = shell_exec($command);
if (! is_file($dest)) {
    fwrite(STDERR, 'ffmpeg failed: '.(string) $output."\n");
    exit(1);
}

echo $variant.' '.(string) filesize($dest)." bytes (ffmpeg)\n";
