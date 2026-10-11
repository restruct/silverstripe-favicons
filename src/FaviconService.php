<?php

declare(strict_types=1);

namespace Restruct\Favicons;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Restruct\Favicons\Generator\IconRenderer;
use Restruct\Favicons\Generator\IcoWriter;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleResourceLoader;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Resolves the project icon, knows which variants exist and at which paths, generates them into the
 * AssetStore, and builds the head tags.
 *
 * Source precedence, first usable one wins:
 *   1. the CMS upload on Settings (SiteConfig.FaviconSource), only with allow_upload: true
 *   2. admintweaks' SiteConfig.FavIcon, when that module is installed and use_admintweaks is on
 *   3. the YAML `source`
 * Every source must be a square PNG of at least MIN_SOURCE_SIZE px.
 *
 * Generated files live in the AssetStore (so S3 and other adapters work), under
 * `_favicons/<set id>/`: a public copy of the source as icon.png, its PNG variants as asset variants
 * of that file, and favicon.ico next to it. None of them gets a File record. The set id is a hash of
 * the source content and the generation config, so a new icon or new config is a new folder, and the
 * previous set is deleted once the new one is complete.
 */
class FaviconService
{
    use Injectable;
    use Configurable;

    /**
     * Bumped whenever the generated output changes for the same input, so browsers and caches that
     * hold a long-lived copy pick up the new rendering, and a new set is generated.
     */
    public const GENERATOR_VERSION = '2';

    /**
     * Uniqueness key for Requirements::insertHeadTags(), so the tags are inserted once even when two
     * controllers in one request (eg Security rendering through a Page controller) both add them.
     */
    public const HEAD_TAGS_ID = 'restruct-favicons';

    /**
     * Smallest accepted source edge: the largest variant (icon-512.png, the maskable icon) is drawn at
     * 512 px, and an upscaled icon looks blurry on every high-density screen.
     */
    public const MIN_SOURCE_SIZE = 512;

    /**
     * The icon as a resource string, resolved through ModuleResourceLoader: "vendor/module:path/icon.png",
     * or a path relative to the project root such as "app/images/icon.png" or
     * "themes/mytheme/images/icon.png". A square PNG of at least 512x512 px.
     *
     * This is one fixed file, not looked up through the theme stack: when the site switches theme,
     * point `source` at the new theme's icon.
     *
     * @config
     */
    private static ?string $source = null;

    /**
     * Show an upload field on Settings (SiteConfig). A published upload overrules every other source.
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
     * Use restruct/silverstripe-admintweaks' SiteConfig.FavIcon as a source (after the upload, before
     * the YAML source) and its SiteConfig.ThemeBrowserColor as theme colour (when theme_color is not
     * set), when that module is installed and the fields are filled in.
     *
     * @config
     */
    private static bool $use_admintweaks = true;

    /**
     * Largest accepted source edge. Decoding a bitmap costs width x height x 4 bytes of memory, so a
     * mistaken 20000 px upload would otherwise exhaust memory_limit on every generation attempt.
     *
     * @config
     */
    private static int $max_source_size = 4096;

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
     * background_color falls back to `background_color`, empty theme_color to the theme colour.
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
     * Emitted as <meta name="theme-color"> and used in the manifest. Null falls back to admintweaks'
     * ThemeBrowserColor (see use_admintweaks), and otherwise emits nothing.
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
     * Seconds a failed generation (a PNG the image backend cannot decode) is remembered, during which
     * the icon variants are neither linked nor retried.
     *
     * @config
     */
    private static int $failure_ttl = 600;

    /**
     * AssetStore folder the generated sets are written to.
     *
     * @config
     */
    private static string $asset_folder = '_favicons';

    /**
     * Cache key of the registry of generated sets: set id => [src hash, ico hash, done].
     */
    protected const SETS_KEY = 'sets';

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
     * The icon to generate from: the first usable candidate, or null.
     */
    public function getSource(): ?IconSource
    {
        foreach ($this->getSourceCandidates() as $candidate) {
            if ($candidate->isUsable()) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Every source that is set, in order of precedence, usable or not.
     *
     * @return IconSource[]
     */
    public function getSourceCandidates(): array
    {
        return array_values(array_filter([
            $this->getUploadSource(),
            $this->getAdmintweaksSource(),
            $this->getConfigSource(),
        ]));
    }

    /**
     * Extensions the upload field accepts. PNG only: an icon needs transparency (so no JPEG), and
     * one bitmap format keeps the generated output identical whichever image backend renders it.
     *
     * @return string[]
     */
    public static function allowedUploadExtensions(): array
    {
        return ['png'];
    }

    public function getUploadSource(): ?IconSource
    {
        if (!$this->config()->get('allow_upload') || !class_exists(SiteConfig::class)) {
            return null;
        }
        return $this->getFileSource((int) SiteConfig::current_site_config()->FaviconSourceID, 'upload');
    }

    /**
     * admintweaks' SiteConfig.FavIcon (a File, any image type). Only a PNG that passes the same
     * checks as every other source is used; anything else is reported and skipped.
     */
    public function getAdmintweaksSource(): ?IconSource
    {
        if (!$this->config()->get('use_admintweaks') || !$this->hasAdmintweaksIcon()) {
            return null;
        }
        return $this->getFileSource((int) SiteConfig::current_site_config()->FavIconID, 'admintweaks');
    }

    /**
     * Whether SiteConfig has admintweaks' FavIcon relation (installed and its extension applied).
     */
    public function hasAdmintweaksIcon(): bool
    {
        return class_exists(SiteConfig::class)
            && DataObject::getSchema()->hasOneComponent(SiteConfig::class, 'FavIcon') !== null;
    }

    protected function getFileSource(int $id, string $label): ?IconSource
    {
        if (!$id) {
            return null;
        }
        $file = $this->getLiveFile($id);
        if (!$file || !$file->exists()) {
            return null;
        }
        $hash = (string) $file->getHash();
        $origin = $label . ':' . $file->getFilename();
        # Validation needs only the PNG header, and is remembered per content hash, so a page request
        # costs one cache read instead of a read from the asset store.
        $key = 'chk_' . md5($hash);
        $error = $this->getCache()->get($key);
        if (!is_string($error)) {
            $error = (string) $this->checkPng($this->readHead($file));
            $this->getCache()->set($key, $error);
        }
        return new IconSource($hash, $origin, fn() => (string) $file->getString(), $error === '' ? null : $error);
    }

    /**
     * The first bytes of a stored file: enough for the PNG signature and header.
     */
    protected function readHead(File $file): string
    {
        $stream = $file->getStream();
        if (!is_resource($stream)) {
            return '';
        }
        $head = (string) fread($stream, 64);
        fclose($stream);
        return $head;
    }

    /**
     * A file as published. Icons are fetched by anonymous visitors, so a draft-only file must never
     * be used: it would leak an unpublished file, or break once the draft is discarded.
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
        $info = $this->inspectFile($path);
        $origin = 'file:' . $resource;
        if ($info['error'] !== '') {
            $this->warnOnce('content:' . $info['hash'], "Favicon source '{$resource}' is unusable: " . $info['error']);
        }
        return new IconSource(
            $info['hash'],
            $origin,
            fn() => (string) file_get_contents($path),
            $info['error'] === '' ? null : $info['error']
        );
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
     * Content hash of a file plus the PNG check, cached on path + mtime + size so a page request
     * does not re-read the file. Keyed on content rather than mtime so a deploy that rewrites the
     * file unchanged does not bust every visitor's cached icon.
     *
     * @return array{hash:string, error:string} error is '' for a usable file
     */
    protected function inspectFile(string $path): array
    {
        $stat = @stat($path) ?: ['mtime' => 0, 'size' => 0];
        $key = 'file_' . md5($path . '|' . $stat['mtime'] . '|' . $stat['size']);
        $cache = $this->getCache();
        $info = $cache->get($key);
        if (!is_array($info) || !isset($info['hash'], $info['error'])) {
            $head = (string) @file_get_contents($path, false, null, 0, 64);
            $info = ['hash' => (string) sha1_file($path), 'error' => (string) $this->checkPng($head)];
            $cache->set($key, $info);
        }
        return $info;
    }

    /**
     * Why these bytes are not a usable icon source, or null when they are: a PNG, square, at least
     * MIN_SOURCE_SIZE and at most max_source_size px. Reads only the header, so the first 24 bytes
     * are enough.
     *
     * Non-square sources are refused rather than padded: padding would put the icon off-centre in
     * some variants and invent a background colour for the margin, and the person uploading it is
     * better placed to decide how it should be cropped.
     */
    public function checkPng(string $bytes): ?string
    {
        $dims = self::pngDimensions($bytes);
        if ($dims === null) {
            return _t(__CLASS__ . '.ERROR_NOT_PNG', 'The file is not a PNG image.');
        }
        [$w, $h] = $dims;
        if ($w !== $h) {
            return _t(
                __CLASS__ . '.ERROR_NOT_SQUARE',
                'The image is {w}x{h} px; it must be square.',
                ['w' => $w, 'h' => $h]
            );
        }
        if ($w < self::MIN_SOURCE_SIZE) {
            return _t(
                __CLASS__ . '.ERROR_TOO_SMALL',
                'The image is {w}x{h} px; it must be at least {min}x{min} px.',
                ['w' => $w, 'h' => $h, 'min' => self::MIN_SOURCE_SIZE]
            );
        }
        $max = (int) $this->config()->get('max_source_size');
        if ($max > 0 && $w > $max) {
            return _t(
                __CLASS__ . '.ERROR_TOO_LARGE',
                'The image is {w}x{h} px; it must be at most {max}x{max} px.',
                ['w' => $w, 'h' => $h, 'max' => $max]
            );
        }
        return null;
    }

    /**
     * Width and height from a PNG's IHDR chunk, or null when the bytes do not start like a PNG.
     * Parsed by hand from the fixed header layout (signature, IHDR length and type, then width and
     * height as big-endian uint32) so no image library is involved in deciding what is accepted.
     *
     * @return array{0:int, 1:int}|null
     */
    public static function pngDimensions(string $bytes): ?array
    {
        if (strlen($bytes) < 24 || !str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || substr($bytes, 12, 4) !== 'IHDR') {
            return null;
        }
        $dims = unpack('Nw/Nh', substr($bytes, 16, 8));
        if (!$dims || $dims['w'] < 1 || $dims['h'] < 1) {
            return null;
        }
        return [(int) $dims['w'], (int) $dims['h']];
    }

    # ---------------------------------------------------------------------------------------------
    # Versioning
    # ---------------------------------------------------------------------------------------------

    /**
     * Id of the generated set: everything that changes the generated files. Also the AssetStore
     * folder name, so a new icon or new config never reuses (or overwrites) an older set's files.
     */
    public function getSetId(IconSource $source): string
    {
        $cfg = $this->config();
        return substr(sha1((string) json_encode([
            self::GENERATOR_VERSION,
            $source->getHash(),
            $cfg->get('background_color'),
            $cfg->get('touch_icon_padding'),
            $cfg->get('maskable_padding'),
            $cfg->get('ico_sizes'),
            $this->getTouchSizes(),
        ])), 0, 16);
    }

    /**
     * Short hash of everything that affects what is served: the set plus (for the manifest) its
     * fields and the site title. Used as the ?v= cache-buster in the head tags and in the ETag.
     */
    public function getVersion(?IconSource $source = null): ?string
    {
        $source ??= $this->getSource();
        if (!$source) {
            return null;
        }
        $parts = [
            $this->getSetId($source),
            $this->config()->get('manifest') ? $this->getManifestData($source) : null,
            # Whether the images can be served changes the manifest's icon list and the responses.
            $this->hasFailed($source),
        ];
        return substr(sha1((string) json_encode($parts)), 0, 16);
    }

    /**
     * Whether generating this source's set failed within the last failure_ttl seconds.
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
        return 'fail_' . $this->getSetId($source);
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
        if (!$this->hasFailed($source)) {
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
     * @return int[] ICO entry sizes within what the format can hold
     */
    public function getIcoSizes(): array
    {
        $sizes = array_map('intval', (array) $this->config()->get('ico_sizes'));
        $sizes = array_values(array_unique(array_filter($sizes, fn($n) => $n >= 1 && $n <= 256)));
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
    # Generation and storage
    # ---------------------------------------------------------------------------------------------

    /**
     * Content type plus either a stream from the AssetStore or (for the manifest) a body, for one
     * variant of the current source. Null when it does not exist or could not be generated.
     *
     * @return array{type:string, stream?:resource, body?:string}|null
     */
    public function openVariant(string $name): ?array
    {
        $source = $this->getSource();
        if (!$source) {
            return null;
        }
        $variants = $this->getVariants($source);
        if (!isset($variants[$name])) {
            return null;
        }
        $spec = $variants[$name];

        # The manifest is small and depends on SiteConfig, so it is built fresh rather than stored.
        if ($spec['format'] === 'manifest') {
            return ['type' => $spec['type'], 'body' => $this->buildManifest($source)];
        }

        # Second attempt only when the set was registered as complete but a file has gone missing
        # from the store since (deleted by hand, or by another server's cleanup).
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $set = $this->ensureSet($source);
            if ($set === null) {
                return null;
            }
            [$filename, $hash, $variant] = $this->locate($set, $spec);
            $stream = $hash ? $this->getStore()->getAsStream($filename, $hash, $variant) : null;
            if (is_resource($stream)) {
                return ['type' => $spec['type'], 'stream' => $stream];
            }
            $this->forgetSet($set['id']);
        }
        return null;
    }

    /**
     * Whether a variant can be served for the current source, generating the set when needed.
     */
    public function canServe(string $name): bool
    {
        $source = $this->getSource();
        $variants = $source ? $this->getVariants($source) : [];
        if (!isset($variants[$name])) {
            return false;
        }
        return $variants[$name]['format'] === 'manifest' || $this->ensureSet($source) !== null;
    }

    /**
     * The registry entry of the source's set, generating it into the AssetStore first if needed.
     * Null when it cannot be generated (the failure is then remembered for failure_ttl).
     *
     * Once a set is complete, any other registered set (an older icon or config) is deleted from
     * the store, so only one set is kept.
     *
     * @return array{id:string, src:string, ico:?string, done:bool}|null
     */
    public function ensureSet(IconSource $source): ?array
    {
        if ($this->hasFailed($source)) {
            return null;
        }
        $setId = $this->getSetId($source);
        $sets = $this->getSets();
        $entry = $sets[$setId] ?? null;
        if (is_array($entry) && !empty($entry['done'])) {
            return ['id' => $setId] + $entry;
        }

        try {
            $entry = $this->buildSet($source, $setId, is_array($entry) ? $entry : null);
        } catch (Throwable $e) {
            $this->warnOnce('gen:' . $setId, 'Favicon generation from ' . $source->getOrigin() . ' failed: ' . $e->getMessage());
            $entry = null;
        }
        if ($entry === null) {
            $this->markFailed($source);
            $this->warnOnce('fail:' . $setId, 'Favicon source ' . $source->getOrigin() . ' could not be decoded by the image backend; no icon variants are served.');
            return null;
        }

        # Re-read: another request may have registered sets meanwhile.
        foreach ($this->getSets() as $id => $old) {
            if ($id !== $setId && is_array($old)) {
                $this->deleteSet((string) $id, $old);
            }
        }
        $this->getCache()->set(self::SETS_KEY, [$setId => $entry]);
        return ['id' => $setId] + $entry;
    }

    /**
     * Generate every file of a set: the public copy of the source, its PNG variants, favicon.ico.
     *
     * @param array<string, mixed>|null $previous An incomplete registry entry for this set
     * @return array{src:string, ico:?string, done:bool}|null
     */
    protected function buildSet(IconSource $source, string $setId, ?array $previous): ?array
    {
        $store = $this->getStore();
        $filename = $this->setPath($setId, 'icon.png');
        $srcHash = (string) ($previous['src'] ?? $source->getHash());

        # The source copy is a plain AssetStore file: no File record, so a YAML or theme source never
        # appears in the CMS, and the folder is owned by this module alone.
        if (!$store->exists($filename, $srcHash)) {
            $result = $store->setFromString($source->getContents(), $filename, null, null, [
                'visibility' => AssetStore::VISIBILITY_PUBLIC,
                'conflict' => AssetStore::CONFLICT_OVERWRITE,
            ]);
            $srcHash = (string) ($result['Hash'] ?? '');
            if ($srcHash === '') {
                return null;
            }
        }
        # Registered before the variants are drawn, so a set that fails half-way is still deleted
        # by the next successful one.
        $this->registerSet($setId, ['src' => $srcHash, 'ico' => null, 'done' => false]);
        $original = $this->getOriginal($setId, $srcHash);

        foreach ($this->getVariants($source) as $spec) {
            if ($spec['format'] === 'png' && $this->renderPng($original, $spec) === null) {
                return null;
            }
        }

        $pngs = [];
        foreach ($this->getIcoSizes() as $size) {
            # Transparent: browser tabs have their own background.
            $variant = $this->renderPng($original, ['size' => $size, 'bg' => null, 'pad' => 0]);
            $png = $variant ? $store->getAsString($variant->getFilename(), $variant->getHash(), $variant->getVariant()) : null;
            if (!$png) {
                return null;
            }
            $pngs[$size] = $png;
        }
        $ico = $store->setFromString(IcoWriter::build($pngs), $this->setPath($setId, 'favicon.ico'), null, null, [
            'visibility' => AssetStore::VISIBILITY_PUBLIC,
            'conflict' => AssetStore::CONFLICT_OVERWRITE,
        ]);

        return ['src' => $srcHash, 'ico' => (string) ($ico['Hash'] ?? ''), 'done' => true];
    }

    /**
     * Draw (or reuse, when the store already has it) one PNG variant of a set's source, through the
     * framework's image backend.
     *
     * @param array<string, mixed> $spec size, bg, pad
     */
    protected function renderPng(DBFile $original, array $spec): ?DBFile
    {
        $result = $original->manipulateImage(
            $this->variantName($spec),
            IconRenderer::singleton()->square((int) $spec['size'], $spec['bg'] ?: null, (float) $spec['pad'])
        );
        return $result instanceof DBFile ? $result : null;
    }

    /**
     * Asset variant name for a spec. The set folder already encodes the source and config, so the
     * name only needs to tell the variants of one set apart.
     *
     * @param array<string, mixed> $spec
     */
    public function variantName(array $spec): string
    {
        $name = 'Favicon' . (int) $spec['size'] . (!empty($spec['bg']) ? 'Opaque' : 'Clear');
        $pad = (int) round((float) ($spec['pad'] ?? 0) * 10);
        return $pad > 0 ? $name . 'Pad' . $pad : $name;
    }

    /**
     * [filename, hash, variant] of a variant in the AssetStore.
     *
     * @param array<string, mixed> $set Registry entry
     * @param array<string, mixed> $spec
     * @return array{0:string, 1:?string, 2:?string}
     */
    protected function locate(array $set, array $spec): array
    {
        if ($spec['format'] === 'ico') {
            return [$this->setPath($set['id'], 'favicon.ico'), $set['ico'] ?: null, null];
        }
        return [$this->setPath($set['id'], 'icon.png'), $set['src'] ?: null, $this->variantName($spec)];
    }

    protected function getOriginal(string $setId, string $hash): DBFile
    {
        /** @var DBFile $file */
        $file = DBFile::create_field(DBFile::class, ['Filename' => $this->setPath($setId, 'icon.png'), 'Hash' => $hash]);
        return $file;
    }

    public function setPath(string $setId, string $name): string
    {
        return trim((string) $this->config()->get('asset_folder'), '/') . '/' . $setId . '/' . $name;
    }

    /**
     * Remove a set's files: the source copy with all its variants, and favicon.ico.
     *
     * @param array<string, mixed> $entry
     */
    protected function deleteSet(string $setId, array $entry): void
    {
        $store = $this->getStore();
        if (!empty($entry['src'])) {
            # AssetStore::delete() removes the file and every variant of it.
            $store->delete($this->setPath($setId, 'icon.png'), (string) $entry['src']);
        }
        if (!empty($entry['ico'])) {
            $store->delete($this->setPath($setId, 'favicon.ico'), (string) $entry['ico']);
        }
    }

    /**
     * Registry of generated sets, kept in the module cache. When that cache is cleared the registry
     * is lost and the set it named is left in the store (a few small files); the next generation
     * then cannot clean it up. See the README for removing such leftovers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSets(): array
    {
        $sets = $this->getCache()->get(self::SETS_KEY);
        return is_array($sets) ? $sets : [];
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function registerSet(string $setId, array $entry): void
    {
        $sets = $this->getSets();
        $sets[$setId] = $entry;
        $this->getCache()->set(self::SETS_KEY, $sets);
    }

    protected function forgetSet(string $setId): void
    {
        $sets = $this->getSets();
        if (isset($sets[$setId])) {
            $sets[$setId]['done'] = false;
            $this->getCache()->set(self::SETS_KEY, $sets);
        }
    }

    public function getStore(): AssetStore
    {
        return Injector::inst()->get(AssetStore::class);
    }

    # ---------------------------------------------------------------------------------------------
    # Manifest and theme colour
    # ---------------------------------------------------------------------------------------------

    /**
     * The theme colour: `theme_color`, else admintweaks' ThemeBrowserColor when set (see
     * use_admintweaks), else null.
     */
    public function getThemeColor(): ?string
    {
        $color = trim((string) $this->config()->get('theme_color'));
        if ($color !== '') {
            return $color;
        }
        if ($this->config()->get('use_admintweaks') && class_exists(SiteConfig::class)
            && SiteConfig::singleton()->hasField('ThemeBrowserColor')
        ) {
            $color = trim((string) SiteConfig::current_site_config()->ThemeBrowserColor);
            # Only a plain hex colour: the field is free text in the CMS, and the value ends up in a
            # meta tag and the manifest.
            if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color)) {
                return $color;
            }
        }
        return null;
    }

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
        $fields['theme_color'] = $fields['theme_color'] ?: $this->getThemeColor();

        $base = rtrim(Director::baseURL(), '/');
        $icons = [];
        if ($source && !$this->hasFailed($source)) {
            $icons[] = ['src' => $base . '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'];
            $icons[] = ['src' => $base . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'];
            $icons[] = ['src' => $base . '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'];
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
     * The modern minimal set: ico, apple-touch-icon, manifest, theme-color. Every URL carries ?v= so
     * a changed icon is picked up despite long cache lifetimes.
     *
     * The set is generated here when it does not exist yet (once per icon or config change), so the
     * tags only ever point at files that could be produced: a PNG the backend cannot decode yields no
     * icon tags rather than a page full of broken links.
     */
    public function getHeadTags(): string
    {
        $source = $this->getSource();
        if (!$source) {
            return '';
        }
        $this->ensureSet($source);
        $variants = $this->getVariants($source);
        $v = $this->getVersion($source);
        $base = rtrim(Director::baseURL(), '/');
        $url = fn(string $name) => htmlspecialchars($base . '/' . $name . '?v=' . $v, ENT_QUOTES);

        $tags = [];
        if (isset($variants['favicon.ico'])) {
            $tags[] = '<link rel="icon" href="' . $url('favicon.ico') . '" sizes="32x32">';
        }
        if (isset($variants['apple-touch-icon.png'])) {
            $tags[] = '<link rel="apple-touch-icon" href="' . $url('apple-touch-icon.png') . '">';
        }
        if (isset($variants['site.webmanifest'])) {
            $tags[] = '<link rel="manifest" href="' . $url('site.webmanifest') . '">';
        }
        $theme = (string) $this->getThemeColor();
        if ($theme !== '') {
            $tags[] = '<meta name="theme-color" content="' . htmlspecialchars($theme, ENT_QUOTES) . '">';
        }
        return implode("\n", $tags);
    }

    # ---------------------------------------------------------------------------------------------
    # Status (CMS and dev/build hints)
    # ---------------------------------------------------------------------------------------------

    /**
     * Human-readable status: which source is active, and why any higher-precedence one was skipped.
     */
    public function getStatusMessage(): string
    {
        $lines = [];
        $active = null;
        foreach ($this->getSourceCandidates() as $candidate) {
            if (!$candidate->isUsable()) {
                $lines[] = _t(
                    __CLASS__ . '.STATUS_SKIPPED',
                    'Skipped {origin}: {error}',
                    ['origin' => $candidate->getOrigin(), 'error' => $candidate->getError()]
                );
                continue;
            }
            $active = $candidate;
            break;
        }
        if (!$active) {
            $lines[] = _t(__CLASS__ . '.STATUS_NONE', 'No usable favicon source; nothing is served.');
        } elseif ($this->ensureSet($active) === null) {
            $lines[] = _t(
                __CLASS__ . '.STATUS_FAILED',
                'The image backend could not read {origin}; no icons are served. Check that it is an intact PNG.',
                ['origin' => $active->getOrigin()]
            );
        } else {
            $lines[] = _t(__CLASS__ . '.STATUS_OK', 'Serving all favicon variants from {origin}.', ['origin' => $active->getOrigin()]);
        }
        return implode(' ', $lines);
    }

    /**
     * Whether a source is set but nothing usable is served from it (for a red dev/build line).
     */
    public function isDegraded(): bool
    {
        $candidates = $this->getSourceCandidates();
        if (!$candidates) {
            return false;
        }
        # Red also when a higher-precedence source is set but skipped: what is served is then not
        # what the site owner set.
        $first = $candidates[0];
        return !$first->isUsable() || $this->ensureSet($first) === null;
    }

    # ---------------------------------------------------------------------------------------------
    # Internals
    # ---------------------------------------------------------------------------------------------

    public function getCache(): CacheInterface
    {
        return Injector::inst()->get(CacheInterface::class . '.RestructFavicons');
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
