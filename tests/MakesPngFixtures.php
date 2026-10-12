<?php

namespace Restruct\Favicons\Tests;

/**
 * Non-square PNG sources drawn with GD at test time, instead of committed fixture files, so the
 * dimensions and the colour layout a test relies on are visible in the test itself.
 *
 * GD is only used here, to make the input; the module itself draws through the Image_Backend.
 */
trait MakesPngFixtures
{
    /**
     * @var string[] Files made by makePng(), removed by removePngFixtures()
     */
    private array $madePngs = [];

    /**
     * A $w x $h PNG: red, with a green square of the shortest side in the exact centre. A centre
     * crop of it is all green; any other crop, or a squash instead of a crop, shows red at an edge.
     */
    protected function makePng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 0, 0));
        $side = min($w, $h);
        $x = intdiv($w - $side, 2);
        $y = intdiv($h - $side, 2);
        imagefilledrectangle($im, $x, $y, $x + $side - 1, $y + $side - 1, imagecolorallocate($im, 0, 255, 0));
        $path = TEMP_PATH . '/favicons-test-' . $w . 'x' . $h . '-' . bin2hex(random_bytes(4)) . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        $this->madePngs[] = $path;
        return $path;
    }

    protected function removePngFixtures(): void
    {
        foreach ($this->madePngs as $path) {
            @unlink($path);
        }
        $this->madePngs = [];
    }
}
