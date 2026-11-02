<?php
# Draws demo-icon.png, the neutral source icon the README screenshots upload: a teal disc with a
# white mountain and an amber sun on a TRANSPARENT background, 1024x1024. Transparent on purpose:
# the screenshots then show which variants get background_color behind the icon.
#
#   php make-demo-icon.php            (writes demo-icon.png next to this script)
#
# Plain GD, no SVG and no external tools. Drawn at 2x and resampled down, because GD's filled
# shapes have no anti-aliasing of their own.

$out = 1024;
$big = $out * 2;
$im = imagecreatetruecolor($big, $big);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
imagealphablending($im, true);

$teal = imagecolorallocate($im, 0x0f, 0x76, 0x6e);
$white = imagecolorallocate($im, 0xff, 0xff, 0xff);
$amber = imagecolorallocate($im, 0xf5, 0x9e, 0x0b);
$c = $big / 2;

# The disc fills 92% of the canvas, so the corners stay transparent.
imagefilledellipse($im, (int) $c, (int) $c, (int) ($big * 0.92), (int) ($big * 0.92), $teal);
# Sun, upper right
imagefilledellipse($im, (int) ($big * 0.66), (int) ($big * 0.34), (int) ($big * 0.18), (int) ($big * 0.18), $amber);
# Two peaks; their base is a chord of the disc, so the shape stays inside it
imagefilledpolygon($im, [
    (int) ($big * 0.14), (int) ($big * 0.70),
    (int) ($big * 0.40), (int) ($big * 0.36),
    (int) ($big * 0.58), (int) ($big * 0.58),
    (int) ($big * 0.68), (int) ($big * 0.47),
    (int) ($big * 0.86), (int) ($big * 0.70),
], $white);

$small = imagecreatetruecolor($out, $out);
imagealphablending($small, false);
imagesavealpha($small, true);
imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
imagecopyresampled($small, $im, 0, 0, 0, 0, $out, $out, $big, $big);
imagepng($small, __DIR__ . '/demo-icon.png', 9);
echo "wrote " . __DIR__ . "/demo-icon.png\n";
