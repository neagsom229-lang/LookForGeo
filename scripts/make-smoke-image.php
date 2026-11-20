<?php

// Generate a small PNG fixture for the smoke test.
// Usage: php scripts/make-smoke-image.php <output.png>

$dest = $argv[1] ?? null;
if (! $dest) {
    fwrite(STDERR, "Usage: php scripts/make-smoke-image.php <output.png>\n");
    exit(1);
}

$image = imagecreatetruecolor(32, 32);
$bg = imagecolorallocate($image, 40, 40, 60);
imagefilledrectangle($image, 0, 0, 31, 31, $bg);
$fg = imagecolorallocate($image, 255, 210, 120);
imagestring($image, 5, 8, 4, 'GO', $fg);

imagepng($image, $dest);
imagedestroy($image);

echo "wrote $dest\n";
