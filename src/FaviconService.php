<?php

declare(strict_types=1);

namespace Restruct\Favicons;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Restruct\Favicons\Generator\ImageProcessor;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Generator\SvgRasteriser;
use Restruct\Favicons\Generator\SvgSanitiser;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Resolves the project icon, knows which variants exist and at which paths, generates and caches
 * them, and builds the head tags.
 *
 * Source precedence: a published CMS upload on SiteConfig (only with allow_upload: true), then the
 * YAML `source`. Everything else is derived from that one file.
 */
class FaviconService
{
    use Injectable;
    use Configurable;

    /**
     * Bumped whenever the generated output changes for the same input, so browsers and caches that
     * hold a long-lived copy pick up the new rendering.
     */
    public const GENERATOR_VERSION = '1';

    /**
     * Uniqueness key for Requirements::insertHeadTags(), so the tags are inserted once even when two
     * controllers in one request (eg Security rendering through a Page controller) both add them.
     */
    public const HEAD_TAGS_ID = 'restruct-favicons';

    /**
     * The icon as a resource string, resolved through ModuleResourceLoader: "vendor/module:path/icon.svg",
     * or a path relative to the project root such as "app/images/icon.png" or
     * "themes/mytheme/images/icon.svg". SVG or PNG.
     *
     * @config
     */
    private static ?string $source = null;

    /**
     * Show an upload field on Settings (SiteConfig). A published upload overrules `source`.
     *
     * @config
     */
    private static bool $allow_upload = false;

    /**
     * Asset folder the upload field stores into.
     *
     * @config
     */
    private static string $upload_folder = 'favicon';

    /**
     * The class whose presence means restruct/silverstripe-svg-images is installed, which is what
     * makes SVG an allowed, sanitised Image upload. Config rather than a constant so a project with a
     * different SVG module can point it elsewhere (and so the tests can simulate either case).
     *
     * @config
     */
    private static string $svg_images_class = 'Restruct\\Silverstripe\\SVG\\SVGImage';

    /**
     * Fill colour for the opaque variants (apple-touch-icon*, maskable). iOS paints transparency
     * black, so these must not be transparent.
     *
     * @config
     */
    private static string $background_color = '#ffffff';

    /**
     * Margin around the icon on the apple-touch-icon variants, % of the edge per side.
     *
     * @config
     */
    private static float $touch_icon_padding = 0;

    /**
     * Margin on the maskable icon, % of the edge per side. Android masks to a circle of 80% of the
     * edge, so 10% keeps the whole icon inside the safe zone.
     *
     * @config
     */
    private static float $maskable_padding = 10;

    /**
     * Sizes embedded in favicon.ico.
     *
     * @config
     */
    private static array $ico_sizes = [16, 32, 48];

    /**
     * apple-touch-icon-NxN.png sizes generated. A probe for any other size (eg 240x240, or 57x57
     * from very old iOS) gets the nearest of these instead of a 404.
     *
     * @config
     */
    private static array $touch_sizes = [120, 152, 167, 180];

    /**
     * Serve /site.webmanifest (also at /manifest.json) and link it from the head.
     *
     * @config
     */
    private static bool $manifest = true;

    /**
     * Web manifest members. Empty name/short_name fall back to the SiteConfig title; empty
     * background_color falls back to `background_color`, empty theme_color to `theme_color`.
     *
     * @config
     */
    private static array $manifest_fields = [
        'name' => null,
        'short_name' => null,
        # Empty means the site's base URL, so a site in a subdirectory gets the right one.
        'start_url' => null,
        # 'browser' keeps the site a normal website when added to a home screen; 'standalone' or
        # 'minimal-ui' would make it open like an app, which is a product decision, not an icon one.
        'display' => 'browser',
        'background_color' => null,
        'theme_color' => null,
    ];

    /**
     * Emitted as <meta name="theme-color"> and used in the manifest. Null emits nothing.
     *
     * @config
     */
    private static ?string $theme_color = null;

    /**
     * Additional request paths to answer, as path => variant, eg
     *   'sites/favicon.ico': 'favicon.ico'
     * The variant is any standard path this module serves.
     *
     * @config
     */
    private static array $extra_paths = [];

    /**
     * Where the <link> tags are added.
     *
     * @config
     */
    private static bool $frontend_tags = true;

    /** @config */
    private static bool $admin_tags = true;

    /** @config */
    private static bool $security_tags = true;

    /**
     * Cache lifetime (seconds) for a bare probe such as /favicon.ico, which cannot carry a version.
     * Revalidation is cheap thanks to the ETag.
     *
     * @config
     */
    private static int $max_age = 86400;

    /**
     * Cache lifetime for a request carrying the current ?v= version (the URLs in the head tags):
     * the content behind such a URL never changes, so it is cached for a year as immutable.
     *
     * @config
     */
    private static int $versioned_max_age = 31536000;

    /**
     * Edge length of the PNG that an SVG source is rasterised to once, and all PNG variants are
     * scaled down from.
     */
    protected const MASTER_SIZE = 512;

    /**
     * Seconds a failed generation (unreadable file, renderer error or timeout) is remembered, during
     * which the bitmap variants are neither linked nor retried.
     *
     * @config
     */
    private static int $failure_ttl = 600;

    /**
     * Logged-once guard per process; the cache keeps it once per source across requests.
     *
     * @var array<string, bool>
     */
    protected array $warned = [];

    # ---------------------------------------------------------------------------------------------
    # Source resolution
    # ---------------------------------------------------------------------------------------------

    /**
     * The icon to generate from, or null when none is configured or usable.
     */
    public function getSource(): ?IconSource
    {
        return $this->getUploadSource() ?? $this->getConfigSource();
    }

    /**
     * Extensions the upload field accepts. SVG only when restruct/silverstripe-svg-images is installed:
     * without it core does not allow .svg uploads at all, nor sanitise them.
     *
     * PNG only for bitmaps: an icon needs transparency, JPEG has none, and WebP/GIF sources would only
     * be converted to PNG anyway while adding decoder differences between GD builds.
     *
     * @return string[]
     */
    public static function allowedUploadExtensions(): array
    {
        $exts = ['png'];
        $svgClass = (string) static::config()->get('svg_images_class');
        if ($svgClass !== '' && class_exists($svgClass)) {
            $exts[] = 'svg';
        }
        return $exts;
    }

    public function getUploadSource(): ?IconSource
    {
        if (!$this->config()->get('allow_upload') || !class_exists(SiteConfig::class)) {
            return null;
        }
        $id = (int) SiteConfig::current_site_config()->FaviconSourceID;
        if (!$id) {
            return null;
        }
        $file = $this->getLiveFile($id);
        if (!$file || !$file->exists()) {
            return null;
        }
        $ext = strtolower((string) $file->getExtension());
        if (!in_array($ext, self::allowedUploadExtensions(), true)) {
            return null;
        }
        return new IconSource(
            $ext,
            (string) $file->getHash(),
            'upload:' . $file->getFilename(),
            fn() => (string) $file->getString()
        );
    }

    /**
     * The upload as published. Icons are fetched by anonymous visitors, so a draft-only file must
     * never be used: it would leak an unpublished file, or break once the draft is discarded.
     */
    protected function getLiveFile(int $id): ?File
    {
        if (class_exists(Versioned::class) && File::singleton()->hasExtension(Versioned::class)) {
            /** @var File|null $file */
            $file = Versioned::get_by_stage(File::class, Versioned::LIVE)->byID($id);
            return $file;
        }
        return File::get()->byID($id);
    }

    public function getConfigSource(): ?IconSource
    {
        $resource = (string) $this->config()->get('source');
        if ($resource === '') {
            return null;
        }
        $path = $this->resolveResourcePath($resource);
        if ($path === null) {
            $this->warnOnce('missing:' . $resource, "Favicon source '{$resource}' could not be found");
            return null;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($ext, [IconSource::TYPE_SVG, IconSource::TYPE_PNG], true)) {
            $this->warnOnce('type:' . $resource, "Favicon source '{$resource}' must be an .svg or .png file");
            return null;
        }
        $info = $this->inspectFile($path, $ext);
        if (!$info['valid']) {
            $this->warnOnce('content:' . $info['hash'], "Favicon source '{$resource}' is not a valid .{$ext} file");
            return null;
        }
        return new IconSource($ext, $info['hash'], 'file:' . $resource, fn() => (string) file_get_contents($path));
    }

    /**
     * Absolute filesystem path for a resource string, or null.
     *
     * ModuleResourceLoader::resolvePath() maps "vendor/module:path" to a project-relative path and
     * returns anything else unchanged, so both forms end up relative to BASE_PATH. An absolute path
     * that exists is accepted as-is (useful for files outside the project, and in tests).
     */
    public function resolveResourcePath(string $resource): ?string
    {
        try {
            $relative = (string) ModuleResourceLoader::singleton()->resolvePath($resource);
        } catch (InvalidArgumentException $e) {
            # Unknown module in "vendor/module:path"
            return null;
        }
        $candidates = [];
        if (str_starts_with($relative, '/')) {
            $candidates[] = $relative;
        }
        $candidates[] = Director::baseFolder() . '/' . ltrim($relative, '/');
        if (defined('PUBLIC_PATH')) {
            $candidates[] = PUBLIC_PATH . '/' . ltrim($relative, '/');
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Content hash of a file plus a content sniff (is it really a PNG/SVG), cached on
     * path + mtime + size so a page request does not re-read the file. Keyed on content rather than
     * mtime so a deploy that rewrites the file unchanged does not bust every visitor's cached icon.
     *
     * @return array{hash:string, valid:bool}
     */
    protected function inspectFile(string $path, string $type): array
    {
        $stat = @stat($path) ?: ['mtime' => 0, 'size' => 0];
        $key = 'file_' . md5($path . '|' . $stat['mtime'] . '|' . $stat['size']);
        $cache = $this->getCache();
        $info = $cache->get($key);
        if (!is_array($info) || !isset($info['hash'], $info['valid'])) {
            $head = (string) @file_get_contents($path, false, null, 0, 4096);
            $info = ['hash' => (string) sha1_file($path), 'valid' => IconSource::looksLike($type, $head)];
            $cache->set($key, $info);
        }
        return $info;
    }

    # ---------------------------------------------------------------------------------------------
    # Capabilities and versioning
    # ---------------------------------------------------------------------------------------------

    /**
     * Whether PNG/ICO variants can be produced from this source: a PNG needs GD or Imagick, an SVG
     * additionally needs a rasteriser.
     */
    public function canRasterise(?IconSource $source = null): bool
    {
        $source ??= $this->getSource();
        if (!$source || !ImageProcessor::isAvailable()) {
            return false;
        }
        if ($this->hasFailed($source)) {
            # A recent attempt failed (bad file, renderer timeout): do not advertise or retry the
            # bitmap variants until the marker expires, so a broken source costs one attempt per
            # failure_ttl instead of one per request.
            return false;
        }
        return !$source->isSvg() || SvgRasteriser::singleton()->getAvailable() !== null;
    }

    /**
     * Whether generating bitmaps from this source failed within the last failure_ttl seconds.
     */
    public function hasFailed(IconSource $source): bool
    {
        return (bool) $this->getCache()->get($this->failureKey($source));
    }

    protected function markFailed(IconSource $source): void
    {
        $this->getCache()->set($this->failureKey($source), 1, max(1, (int) $this->config()->get('failure_ttl')));
    }

    protected function failureKey(IconSource $source): string
    {
        return 'fail_' . self::GENERATOR_VERSION . '_' . $source->getHash();
    }

    /**
     * Short hash of everything that affects the generated output: source content, the module's
     * generator version, the relevant config and (for the manifest) the site title. Used as the
     * ?v= cache-buster in the head tags, in the ETag, and as the cache key prefix.
     */
    public function getVersion(?IconSource $source = null): ?string
    {
        $source ??= $this->getSource();
        if (!$source) {
            return null;
        }
        $cfg = $this->config();
        $parts = [
            self::GENERATOR_VERSION,
            $source->getType(),
            $source->getHash(),
            $cfg->get('background_color'),
            $cfg->get('touch_icon_padding'),
            $cfg->get('maskable_padding'),
            $cfg->get('ico_sizes'),
            $cfg->get('touch_sizes'),
            $cfg->get('manifest') ? $this->getManifestData($source) : null,
            # Whether PNGs can be made changes what is served (and the manifest's icon list).
            $this->canRasterise($source),
        ];
        return substr(sha1(json_encode($parts)), 0, 16);
    }

    # ---------------------------------------------------------------------------------------------
    # Variants and paths
    # ---------------------------------------------------------------------------------------------

    /**
     * The variants this source can be served as, name => spec. The name is also the request path.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getVariants(?IconSource $source = null): array
    {
        $source ??= $this->getSource();
        if (!$source) {
            return [];
        }
        $cfg = $this->config();
        $variants = [];
        if ($this->canRasterise($source)) {
            $bg = (string) $cfg->get('background_color');
            $touchPad = (float) $cfg->get('touch_icon_padding');
            $variants['favicon.ico'] = ['format' => 'ico', 'type' => 'image/x-icon'];
            $variants['apple-touch-icon.png'] = ['format' => 'png', 'size' => 180, 'bg' => $bg, 'pad' => $touchPad];
            foreach ($this->getTouchSizes() as $n) {
                $variants["apple-touch-icon-{$n}x{$n}.png"] = ['format' => 'png', 'size' => $n, 'bg' => $bg, 'pad' => $touchPad];
            }
            $variants['icon-192.png'] = ['format' => 'png', 'size' => 192, 'bg' => null, 'pad' => 0];
            $variants['icon-512.png'] = ['format' => 'png', 'size' => 512, 'bg' => null, 'pad' => 0];
            $variants['icon-maskable-512.png'] = [
                'format' => 'png', 'size' => 512, 'bg' => $bg, 'pad' => (float) $cfg->get('maskable_padding'),
            ];
        }
        if ($source->isSvg()) {
            $variants['icon.svg'] = ['format' => 'svg', 'type' => 'image/svg+xml'];
        }
        if ($cfg->get('manifest')) {
            $variants['site.webmanifest'] = ['format' => 'manifest', 'type' => 'application/manifest+json'];
        }
        foreach ($variants as $name => $spec) {
            if (($spec['format'] ?? '') === 'png') {
                $variants[$name]['type'] = 'image/png';
            }
        }
        return $variants;
    }

    /**
     * @return int[] Sorted touch sizes, always including 180 (the size apple-touch-icon.png is)
     */
    public function getTouchSizes(): array
    {
        $sizes = array_map('intval', (array) $this->config()->get('touch_sizes'));
        $sizes[] = 180;
        $sizes = array_values(array_unique(array_filter($sizes, fn($n) => $n >= 16 && $n <= 1024)));
        sort($sizes);
        return $sizes;
    }

    /**
     * Map a request path (relative to the site base, no leading slash) to the variant name that
     * answers it, or null when this module does not serve that path.
     *
     * Covers the standard names, /manifest.json, every apple-touch-icon[-NxN][-precomposed].png
     * probe (nearest generated size), and `extra_paths`. Whether the variant can actually be produced
     * for the current source is checked when serving.
     */
    public function resolveRequestPath(string $path): ?string
    {
        $path = ltrim($path, '/');
        $lower = strtolower($path);

        $extra = (array) $this->config()->get('extra_paths');
        foreach ($extra as $from => $to) {
            if ($to && strtolower(ltrim((string) $from, '/')) === $lower) {
                # Resolved against the built-in names only (so an alias may point at eg
                # apple-touch-icon-precomposed.png), never against other aliases: alias chains or
                # cycles (a -> b, b -> a) cannot recurse.
                return $this->resolveBuiltinPath(strtolower(ltrim((string) $to, '/')));
            }
        }

        return $this->resolveBuiltinPath($lower);
    }

    /**
     * The standard names and probe patterns, without extra_paths.
     */
    protected function resolveBuiltinPath(string $lower): ?string
    {
        switch ($lower) {
            case 'favicon.ico':
            case 'icon.svg':
            case 'icon-192.png':
            case 'icon-512.png':
            case 'icon-maskable-512.png':
            case 'site.webmanifest':
                return $lower;
            case 'manifest.json':
                return 'site.webmanifest';
        }

        if (preg_match('/^apple-touch-icon(?:-(\d{1,4})x(\d{1,4}))?(?:-precomposed)?\.png$/', $lower, $m)) {
            if (!isset($m[1]) || $m[1] === '') {
                return 'apple-touch-icon.png';
            }
            # Devices only ever probe square sizes; 0x0 or 120x152 is a scanner, not a device.
            if ((int) $m[1] < 1 || $m[1] !== $m[2]) {
                return null;
            }
            $n = $this->nearest((int) $m[1], $this->getTouchSizes());
            return $n === 180 ? 'apple-touch-icon.png' : "apple-touch-icon-{$n}x{$n}.png";
        }

        return null;
    }

    /**
     * @param int[] $sizes
     */
    protected function nearest(int $wanted, array $sizes): int
    {
        $best = $sizes[0];
        foreach ($sizes as $size) {
            # On a tie prefer the larger size: downscaling on the device looks better than upscaling.
            if (abs($size - $wanted) < abs($best - $wanted) || (abs($size - $wanted) === abs($best - $wanted) && $size > $best)) {
                $best = $size;
            }
        }
        return $best;
    }

    # ---------------------------------------------------------------------------------------------
    # Generation
    # ---------------------------------------------------------------------------------------------

    /**
     * Body and content type for a variant, generated once per source version and cached.
     * Null when the variant does not exist for this source or could not be generated.
     *
     * @return array{body:string, type:string}|null
     */
    public function getVariant(string $name): ?array
    {
        $source = $this->getSource();
        if (!$source) {
            return null;
        }
        $variants = $this->getVariants($source);
        if (!isset($variants[$name])) {
            if ($source->isSvg() && !$this->canRasterise($source)) {
                $this->warnNoRasteriser($source);
            }
            return null;
        }
        $spec = $variants[$name];

        # The manifest is small and depends on SiteConfig, so it is built fresh rather than cached.
        if ($spec['format'] === 'manifest') {
            return ['body' => $this->buildManifest($source), 'type' => $spec['type']];
        }

        $cache = $this->getCache();
        $key = 'v_' . $this->getVersion($source) . '_' . md5($name);
        $body = $cache->get($key);
        if (!is_string($body) || $body === '') {
            try {
                $body = $this->generate($source, $spec);
            } catch (Throwable $e) {
                $this->markFailed($source);
                $this->warnOnce('gen:' . $source->getHash() . $name, "Favicon variant {$name} failed: " . $e->getMessage());
                return null;
            }
            if ($body === null) {
                return null;
            }
            $cache->set($key, $body);
        }
        return ['body' => $body, 'type' => $spec['type']];
    }

    /**
     * @param array<string, mixed> $spec
     */
    protected function generate(IconSource $source, array $spec): ?string
    {
        switch ($spec['format']) {
            case 'svg':
                return $source->getContents();
            case 'png':
                $master = $this->getMasterPng($source);
                return $master === null
                    ? null
                    : ImageProcessor::singleton()->square($master, (int) $spec['size'], $spec['bg'], (float) $spec['pad']);
            case 'ico':
                $master = $this->getMasterPng($source);
                if ($master === null) {
                    return null;
                }
                $pngs = [];
                foreach ((array) $this->config()->get('ico_sizes') as $size) {
                    $size = (int) $size;
                    if ($size >= 1 && $size <= 256) {
                        # Transparent: browser tabs have their own background.
                        $pngs[$size] = ImageProcessor::singleton()->square($master, $size);
                    }
                }
                return $pngs ? IcoWriter::build($pngs) : null;
        }
        return null;
    }

    /**
     * The source as a PNG to scale every variant from: the PNG itself, or the SVG rasterised once at
     * MASTER_SIZE. Cached per source hash so an SVG is rasterised once, not once per variant.
     */
    protected function getMasterPng(IconSource $source): ?string
    {
        if (!$source->isSvg()) {
            return $source->getContents();
        }
        $cache = $this->getCache();
        $key = 'master_' . self::GENERATOR_VERSION . '_' . $source->getHash();
        $png = $cache->get($key);
        if (is_string($png) && $png !== '') {
            return $png;
        }
        if ($this->hasFailed($source)) {
            return null;
        }
        # Strip external references before ANY rasteriser sees the file: the result is published.
        $svg = SvgSanitiser::forRasterising($source->getContents());
        $png = $svg === null ? null : SvgRasteriser::singleton()->rasterise($svg, self::MASTER_SIZE);
        if ($png === null) {
            $this->markFailed($source);
            if ($svg === null) {
                $this->warnOnce('unsafe:' . $source->getHash(), 'Favicon source ' . $source->getOrigin() . ' could not be parsed as a safe SVG; only icon.svg is served.');
            } else {
                $this->warnNoRasteriser($source);
            }
            return null;
        }
        $cache->set($key, $png);
        return $png;
    }

    # ---------------------------------------------------------------------------------------------
    # Manifest
    # ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getManifestData(?IconSource $source = null): array
    {
        $source ??= $this->getSource();
        $cfg = $this->config();
        $fields = array_merge(
            ['name' => null, 'short_name' => null, 'start_url' => null, 'display' => 'browser', 'background_color' => null, 'theme_color' => null],
            (array) $cfg->get('manifest_fields')
        );
        $title = '';
        if (class_exists(SiteConfig::class)) {
            $title = (string) SiteConfig::current_site_config()->Title;
        }
        $fields['name'] = $fields['name'] ?: $title;
        $fields['short_name'] = $fields['short_name'] ?: $fields['name'];
        $fields['start_url'] = $fields['start_url'] ?: Director::baseURL();
        $fields['background_color'] = $fields['background_color'] ?: $cfg->get('background_color');
        $fields['theme_color'] = $fields['theme_color'] ?: $cfg->get('theme_color');

        $base = rtrim(Director::baseURL(), '/');
        $icons = [];
        if ($source && $this->canRasterise($source)) {
            $icons[] = ['src' => $base . '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'];
            $icons[] = ['src' => $base . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'];
            $icons[] = ['src' => $base . '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
        }
        if ($source && $source->isSvg()) {
            $icons[] = ['src' => $base . '/icon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'];
        }
        $fields['icons'] = $icons;

        # Drop empty members rather than emitting nulls, which some validators flag.
        return array_filter($fields, fn($v) => $v !== null && $v !== '' && $v !== []);
    }

    public function buildManifest(?IconSource $source = null): string
    {
        return (string) json_encode(
            $this->getManifestData($source),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    # ---------------------------------------------------------------------------------------------
    # Head tags
    # ---------------------------------------------------------------------------------------------

    /**
     * Whether tags should be added in a context: 'frontend', 'admin' or 'security'.
     */
    public function tagsEnabledFor(string $context): bool
    {
        return (bool) $this->config()->get($context . '_tags');
    }

    /**
     * The modern minimal set: ico, svg (when the source is one), apple-touch-icon, manifest,
     * theme-color. Every URL carries ?v= so a changed icon is picked up despite long cache lifetimes.
     */
    public function getHeadTags(): string
    {
        $source = $this->getSource();
        if (!$source) {
            return '';
        }
        $variants = $this->getVariants($source);
        $v = $this->getVersion($source);
        $base = rtrim(Director::baseURL(), '/');
        $url = fn(string $name) => htmlspecialchars($base . '/' . $name . '?v=' . $v, ENT_QUOTES);

        $tags = [];
        if (isset($variants['favicon.ico'])) {
            $tags[] = '<link rel="icon" href="' . $url('favicon.ico') . '" sizes="32x32">';
        }
        if (isset($variants['icon.svg'])) {
            $tags[] = '<link rel="icon" href="' . $url('icon.svg') . '" type="image/svg+xml">';
        }
        if (isset($variants['apple-touch-icon.png'])) {
            $tags[] = '<link rel="apple-touch-icon" href="' . $url('apple-touch-icon.png') . '">';
        }
        if (isset($variants['site.webmanifest'])) {
            $tags[] = '<link rel="manifest" href="' . $url('site.webmanifest') . '">';
        }
        $theme = (string) $this->config()->get('theme_color');
        if ($theme !== '') {
            $tags[] = '<meta name="theme-color" content="' . htmlspecialchars($theme, ENT_QUOTES) . '">';
        }
        return implode("\n", $tags);
    }

    # ---------------------------------------------------------------------------------------------
    # Status (CMS and dev/build hints)
    # ---------------------------------------------------------------------------------------------

    /**
     * One-line human-readable status: which source is active and what can be served from it.
     */
    public function getStatusMessage(): string
    {
        $source = $this->getSource();
        if (!$source) {
            return _t(__CLASS__ . '.STATUS_NONE', 'No favicon source configured; nothing is served.');
        }
        if ($this->canRasterise($source)) {
            $msg = _t(__CLASS__ . '.STATUS_OK', 'Serving all favicon variants from {origin}.', ['origin' => $source->getOrigin()]);
            if (!$source->isSvg()) {
                $dims = ImageProcessor::dimensions($source->getContents());
                if ($dims && min($dims) < self::MASTER_SIZE) {
                    $msg .= ' ' . _t(
                        __CLASS__ . '.STATUS_SMALL',
                        'The source is {w}x{h} px; use at least 512x512 px for sharp touch icons.',
                        ['w' => $dims[0], 'h' => $dims[1]]
                    );
                }
            }
            return $msg;
        }
        if ($source->isSvg()) {
            return _t(
                __CLASS__ . '.STATUS_NO_RASTERISER',
                'Only icon.svg is served from {origin}: this server cannot convert SVG to PNG (install the Imagick extension with SVG support, or rsvg-convert), so favicon.ico and the touch icons are missing. Upload a PNG or configure a PNG source to serve them.',
                ['origin' => $source->getOrigin()]
            );
        }
        return _t(__CLASS__ . '.STATUS_NO_GD', 'Neither GD nor Imagick is available, so no PNG variants can be generated.');
    }

    # ---------------------------------------------------------------------------------------------
    # Internals
    # ---------------------------------------------------------------------------------------------

    public function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.RestructFavicons');
    }

    protected function warnNoRasteriser(IconSource $source): void
    {
        $this->warnOnce(
            'norast:' . $source->getHash(),
            'Favicon source ' . $source->getOrigin() . ' is SVG and no SVG rasteriser is available;'
            . ' only icon.svg is served. Install ext-imagick with an SVG delegate, or rsvg-convert, or use a PNG source.'
        );
    }

    /**
     * Log a warning once per key: per process via a property, across requests via the cache, so a
     * misconfiguration produces one log line rather than one per icon request.
     */
    protected function warnOnce(string $key, string $message): void
    {
        if (isset($this->warned[$key])) {
            return;
        }
        $this->warned[$key] = true;
        $cacheKey = 'warned_' . md5($key);
        try {
            $cache = $this->getCache();
            if ($cache->get($cacheKey)) {
                return;
            }
            $cache->set($cacheKey, 1, 86400);
        } catch (Throwable $e) {
            # A broken cache must not stop the warning itself.
        }
        Injector::inst()->get(LoggerInterface::class)->warning($message);
    }
}
