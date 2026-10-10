<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Turns a PNG into a square PNG of a given size: scaled to fit, centred, optionally padded and
 * optionally flattened onto a background colour.
 *
 * Talks to GD (or Imagick when GD is missing) directly instead of going through Silverstripe's
 * Image API: the variants live in a cache, not the asset store, and SS5 ships Intervention Image v2
 * while SS6 ships v3 with a different API, so going through Intervention would need two code paths
 * for what is a dozen lines of GD.
 */
class ImageProcessor
{
    use Injectable;

    public static function isAvailable(): bool
    {
        return self::hasGd() || self::hasImagick();
    }

    public static function hasGd(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    public static function hasImagick(): bool
    {
        return class_exists(\Imagick::class);
    }

    /**
     * @param string $png Source PNG bytes (any dimensions; non-square sources are letterboxed)
     * @param int $size Output edge in px
     * @param string|null $background Hex colour (#rgb or #rrggbb) to flatten onto, null keeps transparency
     * @param float $paddingPercent Empty margin on each side, as a percentage of the edge (0-40)
     */
    public function square(string $png, int $size, ?string $background = null, float $paddingPercent = 0): string
    {
        $paddingPercent = max(0.0, min(40.0, $paddingPercent));
        if (self::hasGd()) {
            return $this->squareGd($png, $size, $background, $paddingPercent);
        }
        if (self::hasImagick()) {
            return $this->squareImagick($png, $size, $background, $paddingPercent);
        }
        throw new RuntimeException('Neither GD nor Imagick is available to resize icons');
    }

    /**
     * Width and height of a PNG, or null when it cannot be read.
     *
     * @return array{0:int, 1:int}|null
     */
    public static function dimensions(string $png): ?array
    {
        $info = @getimagesizefromstring($png);
        return $info ? [(int) $info[0], (int) $info[1]] : null;
    }

    protected function squareGd(string $png, int $size, ?string $background, float $paddingPercent): string
    {
        $src = @imagecreatefromstring($png);
        if (!$src) {
            throw new RuntimeException('GD could not read the source image');
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        $canvas = imagecreatetruecolor($size, $size);
        if ($background !== null) {
            [$r, $g, $b] = self::hexToRgb($background);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
        } else {
            # Start from fully transparent: switch blending off to write the alpha channel itself,
            # then on again so the resampled source is composited rather than copied raw.
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagealphablending($canvas, true);
        }
        imagesavealpha($canvas, $background === null);

        [$dx, $dy, $dw, $dh] = self::fit($srcW, $srcH, $size, $paddingPercent);
        imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $srcW, $srcH);

        ob_start();
        imagepng($canvas, null, 9);
        $out = (string) ob_get_clean();
        imagedestroy($src);
        imagedestroy($canvas);
        return $out;
    }

    protected function squareImagick(string $png, int $size, ?string $background, float $paddingPercent): string
    {
        $src = new \Imagick();
        $src->readImageBlob($png);
        [$dx, $dy, $dw, $dh] = self::fit($src->getImageWidth(), $src->getImageHeight(), $size, $paddingPercent);
        $src->resizeImage($dw, $dh, \Imagick::FILTER_LANCZOS, 1);

        $canvas = new \Imagick();
        $canvas->newImage($size, $size, new \ImagickPixel($background ?? 'transparent'));
        $canvas->setImageFormat('png32');
        $canvas->compositeImage($src, \Imagick::COMPOSITE_OVER, $dx, $dy);
        if ($background !== null) {
            $canvas->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }
        return $canvas->getImageBlob();
    }

    /**
     * Destination rectangle that fits a srcW x srcH image inside the padded square, centred.
     *
     * @return array{0:int, 1:int, 2:int, 3:int} x, y, width, height
     */
    public static function fit(int $srcW, int $srcH, int $size, float $paddingPercent): array
    {
        $inner = max(1, (int) round($size * (1 - 2 * $paddingPercent / 100)));
        $scale = $inner / max(1, $srcW, $srcH);
        $dw = max(1, (int) round($srcW * $scale));
        $dh = max(1, (int) round($srcH * $scale));
        return [intdiv($size - $dw, 2), intdiv($size - $dh, 2), $dw, $dh];
    }

    /**
     * @return array{0:int, 1:int, 2:int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            # A typo in YAML should not take the icons down; fall back to white, the iOS-safe default.
            return [255, 255, 255];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
