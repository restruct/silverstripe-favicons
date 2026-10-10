<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders an SVG to a PNG, with whatever the server offers. GD cannot read SVG at all, so this
 * tries, in the configured order:
 *
 * - `imagick`: the Imagick extension, when ImageMagick reports an SVG reader (librsvg or its
 *   built-in MSVG renderer);
 * - `rsvg-convert`: the librsvg command-line tool (Debian/Ubuntu package `librsvg2-bin`, Homebrew
 *   `librsvg`), run without a shell, SVG on stdin and PNG on stdout.
 *
 * When neither is there the module still serves icon.svg and a manifest pointing at it; the PNG
 * and ICO variants are skipped (see FaviconService::canRasterise()).
 */
class SvgRasteriser
{
    use Injectable;
    use Configurable;

    /**
     * Rasterisers to try, in order. Remove one to rule it out (eg on a server where ImageMagick's
     * built-in MSVG renderer draws an icon badly).
     *
     * @config
     */
    private static array $rasterisers = ['imagick', 'rsvg-convert'];

    /**
     * Name or absolute path of the rsvg-convert binary. A bare name is looked up on PATH and in the
     * usual install directories, because php-fpm often runs with an almost empty PATH.
     *
     * @config
     */
    private static string $rsvg_convert_path = 'rsvg-convert';

    /**
     * Name of the first usable rasteriser, or null.
     */
    public function getAvailable(): ?string
    {
        # Asked on every page request (head tags, version hash), and the PATH scan is a dozen stat
        # calls, so remember the answer per config state for the life of the process.
        $key = json_encode([$this->config()->get('rasterisers'), $this->config()->get('rsvg_convert_path')]);
        if (array_key_exists($key, $this->availableMemo)) {
            return $this->availableMemo[$key];
        }
        $found = null;
        foreach ((array) $this->config()->get('rasterisers') as $name) {
            if ($name === 'imagick' && $this->imagickCanReadSvg()) {
                $found = 'imagick';
                break;
            }
            if ($name === 'rsvg-convert' && $this->findRsvgConvert()) {
                $found = 'rsvg-convert';
                break;
            }
        }
        return $this->availableMemo[$key] = $found;
    }

    /** @var array<string, string|null> */
    protected array $availableMemo = [];

    /**
     * Render the SVG so it fits a size x size box (aspect kept, transparent background).
     * Returns null when no rasteriser is available or rendering failed.
     */
    public function rasterise(string $svg, int $size): ?string
    {
        foreach ((array) $this->config()->get('rasterisers') as $name) {
            $png = null;
            if ($name === 'imagick' && $this->imagickCanReadSvg()) {
                $png = $this->viaImagick($svg, $size);
            } elseif ($name === 'rsvg-convert' && ($bin = $this->findRsvgConvert())) {
                $png = $this->viaRsvgConvert($bin, $svg, $size);
            }
            # A rasteriser that is present but fails on this file falls through to the next one.
            if ($png !== null && str_starts_with($png, "\x89PNG")) {
                return $png;
            }
        }
        return null;
    }

    public function imagickCanReadSvg(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }
        try {
            return (bool) \Imagick::queryFormats('SVG');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function findRsvgConvert(): ?string
    {
        # proc_open is the usual casualty of disable_functions on shared hosting; function_exists
        # reports disabled functions as missing on PHP 8.
        if (!function_exists('proc_open')) {
            return null;
        }
        $bin = (string) $this->config()->get('rsvg_convert_path');
        if ($bin === '') {
            return null;
        }
        if (str_contains($bin, '/')) {
            return is_file($bin) && is_executable($bin) ? $bin : null;
        }
        $dirs = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $dirs = array_unique(array_merge($dirs, ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']));
        foreach ($dirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $bin;
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    protected function viaImagick(string $svg, int $size): ?string
    {
        try {
            $im = new \Imagick();
            $im->setBackgroundColor(new \ImagickPixel('transparent'));
            # Render at a high density so the downscale below has detail to work from; SVGs often
            # declare a small width/height (eg 32x32) that would otherwise rasterise tiny and blurry.
            $im->setResolution(384, 384);
            $im->readImageBlob($svg);
            $im->setImageFormat('png32');
            $im->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1, true);
            $png = $im->getImageBlob();
            $im->clear();
            return $png;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function viaRsvgConvert(string $bin, string $svg, int $size): ?string
    {
        # Array form: no shell, so nothing in the arguments can be interpreted as shell syntax.
        $cmd = [$bin, '--width', (string) $size, '--height', (string) $size, '--keep-aspect-ratio', '--format', 'png'];
        $proc = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        fwrite($pipes[0], $svg);
        fclose($pipes[0]);
        $png = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        return ($exit === 0 && $png) ? $png : null;
    }
}
