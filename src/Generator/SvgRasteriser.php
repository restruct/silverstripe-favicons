<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;

/**
 * Renders an SVG to a PNG, with whatever the server offers. GD cannot read SVG at all, so this
 * uses the FIRST AVAILABLE of, in the configured order (and never falls through to the next):
 *
 * - `rsvg-convert`: the librsvg command-line tool (Debian/Ubuntu package `librsvg2-bin`, Homebrew
 *   `librsvg`), run without a shell, SVG on stdin and PNG on stdout, under a deadline. First because
 *   librsvg refuses to load external files from stdin input, and the process can be killed.
 * - `imagick`: the Imagick extension, when ImageMagick reports an SVG reader (librsvg, its built-in
 *   MSVG renderer or an Inkscape delegate). Depending on the delegate it follows local file
 *   references and cannot be interrupted, so it is only the fallback where rsvg-convert is absent,
 *   and runs under pixel and memory limits.
 *
 * Callers pass SVG that went through SvgSanitiser::forRasterising() (FaviconService does), and
 * rasterise() refuses SVGs declaring absurd dimensions or asking for an oversized output.
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
    private static array $rasterisers = ['rsvg-convert', 'imagick'];

    /**
     * Name or absolute path of the rsvg-convert binary. A bare name is looked up on PATH and in the
     * usual install directories, because php-fpm often runs with an almost empty PATH.
     *
     * @config
     */
    private static string $rsvg_convert_path = 'rsvg-convert';

    /**
     * Seconds rsvg-convert may run before it is killed.
     *
     * @config
     */
    private static int $rsvg_timeout = 5;

    /**
     * Seconds the "render in progress" marker lives when Imagick is the active rasteriser. Imagick
     * cannot be interrupted, so this is a generous guess at its worst case rather than a deadline.
     *
     * @config
     */
    private static int $imagick_busy_ttl = 60;

    /**
     * Largest output edge rasterise() will produce, px.
     */
    public const MAX_OUTPUT_SIZE = 2048;

    /**
     * Declared width/height/viewBox beyond this are refused: real icons are tens to a few thousand
     * user units, and ImageMagick's work grows with the declared size (a 20000px declaration ran
     * past two minutes in a measurement).
     */
    public const MAX_DECLARED_SIZE = 10000;

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
        if ($size < 1 || $size > self::MAX_OUTPUT_SIZE) {
            return null;
        }
        $declared = self::declaredSize($svg);
        if ($declared === null) {
            # Unparseable, or dimensions out of range: refuse rather than let a renderer guess.
            return null;
        }
        # Exactly ONE rasteriser runs: the first available in the configured order (rsvg-convert by
        # default). There is deliberately no fall-through: a file rsvg-convert refused or timed out
        # on is exactly the file not to hand to ImageMagick, whose SVG delegates follow references
        # and cannot be interrupted. Imagick is therefore only used where rsvg-convert is absent.
        $png = null;
        switch ($this->getAvailable()) {
            case 'rsvg-convert':
                $png = $this->viaRsvgConvert((string) $this->findRsvgConvert(), $svg, $size);
                break;
            case 'imagick':
                $png = $this->viaImagick($svg, $size, $declared);
                break;
        }
        return ($png !== null && str_starts_with($png, "\x89PNG")) ? $png : null;
    }

    /**
     * Lifetime of FaviconService's in-progress marker for the active rasteriser: just past the
     * rsvg-convert deadline (the process is killed by then), or imagick_busy_ttl for Imagick.
     */
    public function getBusyTtl(): int
    {
        if ($this->getAvailable() === 'imagick') {
            return max(1, (int) $this->config()->get('imagick_busy_ttl'));
        }
        return max(1, (int) $this->config()->get('rsvg_timeout')) + 5;
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

    /**
     * The SVG's declared size in CSS px (width/height, else the viewBox), [w, h]. Percentages and
     * missing values fall back to the viewBox, then to 300x150 (the CSS default for replaced
     * elements). Null when the document cannot be parsed or a dimension is not finite, not positive or
     * beyond MAX_DECLARED_SIZE.
     *
     * @return array{0:float, 1:float}|null
     */
    public static function declaredSize(string $svg): ?array
    {
        $doc = SvgSanitiser::parse($svg);
        if (!$doc) {
            return null;
        }
        $root = $doc->documentElement;
        $vb = preg_split('/[\s,]+/', trim((string) $root->getAttribute('viewBox')));
        $vbW = (count($vb) === 4 && is_numeric($vb[2])) ? (float) $vb[2] : null;
        $vbH = (count($vb) === 4 && is_numeric($vb[3])) ? (float) $vb[3] : null;
        foreach ([$vbW, $vbH] as $v) {
            if ($v !== null && (!is_finite($v) || $v <= 0 || $v > self::MAX_DECLARED_SIZE)) {
                return null;
            }
        }
        $w = self::toPx((string) $root->getAttribute('width')) ?? $vbW ?? 300.0;
        $h = self::toPx((string) $root->getAttribute('height')) ?? $vbH ?? 150.0;
        foreach ([$w, $h] as $v) {
            if (!is_finite($v) || $v <= 0 || $v > self::MAX_DECLARED_SIZE) {
                return null;
            }
        }
        return [$w, $h];
    }

    /**
     * A length attribute in px, or null when absent or relative (%, em).
     */
    protected static function toPx(string $value): ?float
    {
        if (!preg_match('/^\s*([0-9]*\.?[0-9]+(?:e[+-]?[0-9]+)?)\s*(px|pt|pc|mm|cm|in)?\s*$/i', $value, $m)) {
            return null;
        }
        $factor = ['' => 1, 'px' => 1, 'pt' => 4 / 3, 'pc' => 16, 'mm' => 96 / 25.4, 'cm' => 96 / 2.54, 'in' => 96];
        return (float) $m[1] * $factor[strtolower($m[2] ?? '')];
    }

    /**
     * Density (dpi) at which ImageMagick renders an SVG of the declared size to about $target px on
     * its longest edge. ImageMagick treats SVG user units as px at 96 dpi, so scaling the density
     * scales the raster; clamped so a tiny or huge declaration cannot ask for an extreme render.
     *
     * @param array{0:float, 1:float} $declared
     */
    public static function densityFor(array $declared, int $target): float
    {
        $longest = max(1.0, $declared[0], $declared[1]);
        return max(1.0, min(2400.0, 96.0 * $target / $longest));
    }

    /**
     * @param array{0:float, 1:float} $declared
     */
    protected function viaImagick(string $svg, int $size, array $declared): ?string
    {
        $restore = $this->applyImagickLimits($size);
        try {
            # No RESOURCETYPE_TIME limit: ImageMagick measures it from process start and it is
            # process-wide, so in a long-lived FPM worker it would fail every later Imagick call
            # (measured: "time limit exceeded" on the next read). Pixel and memory limits are applied
            # instead (applyImagickLimits), plus the declared-size cap and the density below.
            $im = $this->createImagick();
            $im->setBackgroundColor('transparent');
            # Render at the density that makes the raster about the target size: no more work than
            # needed for a huge declaration, and enough detail for a tiny one (eg width="32").
            $density = self::densityFor($declared, $size);
            $im->setResolution($density, $density);
            $im->readImageBlob($svg);
            $im->setImageFormat('png32');
            $im->resizeImage($size, $size, defined('Imagick::FILTER_LANCZOS') ? constant('Imagick::FILTER_LANCZOS') : 22, 1, true);
            $png = $im->getImageBlob();
            $im->clear();
            return $png;
        } catch (\Throwable $e) {
            return null;
        } finally {
            $this->restoreImagickLimits($restore);
        }
    }

    /**
     * Seam for the tests (they cannot assume ext-imagick): the object viaImagick() drives.
     *
     * @return \Imagick
     */
    protected function createImagick(): object
    {
        return new \Imagick();
    }

    /**
     * Cap ImageMagick's pixel cache for this render: width, height, area, memory, map and disk,
     * whichever this Imagick/ImageMagick (6 or 7) build exposes. The limits are process-wide, so the
     * previous values are returned for restoreImagickLimits().
     *
     * @return array<int, int|float> resource type => previous limit
     */
    protected function applyImagickLimits(int $size): array
    {
        if (!class_exists(\Imagick::class)) {
            return [];
        }
        $edge = 4 * $size;
        $limits = [
            'RESOURCETYPE_WIDTH' => $edge,
            'RESOURCETYPE_HEIGHT' => $edge,
            'RESOURCETYPE_AREA' => $edge * $edge,
            'RESOURCETYPE_MEMORY' => 256 * 1024 * 1024,
            'RESOURCETYPE_MAP' => 512 * 1024 * 1024,
            'RESOURCETYPE_DISK' => 1024 * 1024 * 1024,
        ];
        $restore = [];
        foreach ($limits as $const => $limit) {
            if (!defined('Imagick::' . $const)) {
                continue;
            }
            $type = constant('Imagick::' . $const);
            try {
                $restore[$type] = \Imagick::getResourceLimit($type);
                \Imagick::setResourceLimit($type, $limit);
            } catch (\Throwable $e) {
                # A build that rejects one limit still gets the others.
            }
        }
        return $restore;
    }

    /**
     * @param array<int, int|float> $restore
     */
    protected function restoreImagickLimits(array $restore): void
    {
        foreach ($restore as $type => $value) {
            try {
                # "Unlimited" comes back as a float at or beyond PHP_INT_MAX, which an int parameter
                # rejects; PHP_INT_MAX is ImageMagick's unlimited too (measured on IM 7.1.2).
                $value = (is_float($value) && $value >= PHP_INT_MAX) ? PHP_INT_MAX : (int) $value;
                \Imagick::setResourceLimit($type, $value);
            } catch (\Throwable $e) {
                # Nothing sensible left to do; the next render sets its own limits again.
            }
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
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        # Feed stdin and drain stdout/stderr together under one deadline: a blocking write could stall
        # on a full pipe, and a renderer stuck on a pathological file must not hold a PHP worker.
        $deadline = microtime(true) + max(1, (int) $this->config()->get('rsvg_timeout'));
        $toWrite = $svg;
        $png = '';
        $timedOut = false;
        $maxOutput = 32 * 1024 * 1024;
        while (true) {
            $read = array_filter([$pipes[1] ?? null, $pipes[2] ?? null], 'is_resource');
            $write = ($toWrite !== '' && is_resource($pipes[0] ?? null)) ? [$pipes[0]] : [];
            if (!$read && !$write) {
                break;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedOut = true;
                break;
            }
            $except = null;
            $ready = @stream_select($read, $write, $except, (int) $left, (int) (fmod($left, 1) * 1e6));
            if ($ready === false) {
                break;
            }
            foreach ($write as $w) {
                # @: the renderer may exit early and close its stdin, which raises a Broken pipe notice.
                $n = @fwrite($w, substr($toWrite, 0, 65536));
                if ($n === false || $n === 0) {
                    $toWrite = '';
                } else {
                    $toWrite = (string) substr($toWrite, $n);
                }
                if ($toWrite === '') {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
            }
            foreach ($read as $r) {
                $chunk = (string) fread($r, 65536);
                if ($r === ($pipes[1] ?? null)) {
                    $png .= $chunk;
                }
                if (feof($r)) {
                    fclose($r);
                    $key = array_search($r, $pipes, true);
                    unset($pipes[$key]);
                }
            }
            if (strlen($png) > $maxOutput) {
                $timedOut = true;
                break;
            }
        }

        if ($timedOut) {
            proc_terminate($proc, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $exit = proc_close($proc);
        return (!$timedOut && $exit === 0 && $png !== '') ? $png : null;
    }
}
