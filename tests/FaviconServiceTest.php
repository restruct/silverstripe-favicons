<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Generator\ImageProcessor;
use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Dev\TestAssetStore;
use Restruct\Favicons\Tests\Stub\FavFakeRasteriser;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class FaviconServiceTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FaviconServiceTest');
        $this->pinFaviconConfig();
        FavFakeRasteriser::reset();
    }

    protected function tearDown(): void
    {
        FavFakeRasteriser::reset();
        TestAssetStore::reset();
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/fixtures/' . $name;
    }

    private function service(): FaviconService
    {
        return FaviconService::singleton();
    }

    private function makeUpload(string $fixture, bool $publish = true, string $class = Image::class): File
    {
        /** @var File $file */
        $file = $class::create();
        $file->setFromLocalFile($this->fixture($fixture), 'favicon/' . $fixture);
        $file->write();
        if ($publish) {
            $file->publishSingle();
        }
        $config = SiteConfig::current_site_config();
        $config->FaviconSourceID = $file->ID;
        $config->write();
        return $file;
    }

    # Source resolution

    public function testYamlSourceAsAbsolutePath()
    {
        $source = $this->service()->getSource();
        $this->assertNotNull($source);
        $this->assertSame('png', $source->getType());
        $this->assertSame(sha1_file($this->fixture('icon.png')), $source->getHash());
    }

    public function testYamlSourceAsModuleResource()
    {
        $path = $this->service()->resolveResourcePath('restruct/silverstripe-favicons:tests/fixtures/icon.svg');
        $this->assertNotNull($path);
        $this->assertSame(realpath($this->fixture('icon.svg')), realpath($path));
    }

    public function testYamlSourceAsProjectRelativePath()
    {
        # A path relative to the project root: the module's own fixture, reached through vendor/
        $path = $this->service()->resolveResourcePath('vendor/restruct/silverstripe-favicons/tests/fixtures/icon.png');
        $this->assertNotNull($path);
        $this->assertSame(realpath($this->fixture('icon.png')), realpath($path));
    }

    public function testUnusableYamlSourcesResolveToNothing()
    {
        $this->assertNull($this->service()->resolveResourcePath('no-such/module:icon.svg'), 'unknown module');
        $this->assertNull($this->service()->resolveResourcePath('app/no-such-icon.png'), 'missing file');

        Config::modify()->set(FaviconService::class, 'source', $this->fixture('fake.png'));
        $this->assertNull($this->service()->getSource(), 'a .png that is not a PNG is rejected');

        Config::modify()->set(FaviconService::class, 'source', __FILE__);
        $this->assertNull($this->service()->getSource(), 'only .svg and .png are accepted');

        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertNull($this->service()->getSource());
        $this->assertSame([], $this->service()->getVariants());
        $this->assertSame('', $this->service()->getHeadTags());
    }

    public function testUploadOverridesYamlWhenAllowed()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $file = $this->makeUpload('small.png');
        $source = $this->service()->getSource();
        $this->assertSame($file->getHash(), $source->getHash());
        $this->assertStringStartsWith('upload:', $source->getOrigin());
    }

    public function testUploadIgnoredWhenNotAllowed()
    {
        $this->makeUpload('small.png');
        $source = $this->service()->getSource();
        $this->assertSame(sha1_file($this->fixture('icon.png')), $source->getHash(), 'YAML source wins');
    }

    public function testUnpublishedUploadIsIgnored()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $this->makeUpload('small.png', false);
        $this->assertStringStartsWith('file:', $this->service()->getSource()->getOrigin());
    }

    public function testSvgUploadNeedsSvgImages()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        Config::modify()->merge(File::class, 'allowed_extensions', ['svg']);
        $this->assertSame(['png'], FaviconService::allowedUploadExtensions());
        $this->makeUpload('icon.svg', true, File::class);
        $this->assertStringStartsWith('file:', $this->service()->getSource()->getOrigin(), 'SVG upload ignored without svg-images');

        # Simulate svg-images being installed
        Config::modify()->set(FaviconService::class, 'svg_images_class', Image::class);
        $this->assertSame(['png', 'svg'], FaviconService::allowedUploadExtensions());
        $source = $this->service()->getSource();
        $this->assertStringStartsWith('upload:', $source->getOrigin());
        $this->assertTrue($source->isSvg());
    }

    # Request paths

    public function testProbePathsResolveToVariants()
    {
        $s = $this->service();
        $this->assertSame('favicon.ico', $s->resolveRequestPath('favicon.ico'));
        $this->assertSame('favicon.ico', $s->resolveRequestPath('/favicon.ico'));
        $this->assertSame('site.webmanifest', $s->resolveRequestPath('manifest.json'));
        $this->assertSame('site.webmanifest', $s->resolveRequestPath('site.webmanifest'));
        $this->assertSame('apple-touch-icon.png', $s->resolveRequestPath('apple-touch-icon.png'));
        $this->assertSame('apple-touch-icon.png', $s->resolveRequestPath('apple-touch-icon-precomposed.png'));
        $this->assertSame('apple-touch-icon-120x120.png', $s->resolveRequestPath('apple-touch-icon-120x120-precomposed.png'));
        $this->assertSame('apple-touch-icon-152x152.png', $s->resolveRequestPath('apple-touch-icon-152x152.png'));
        $this->assertSame('apple-touch-icon-167x167.png', $s->resolveRequestPath('apple-touch-icon-167x167.png'));
        # Sizes not generated get the nearest one; 180 is apple-touch-icon.png itself
        $this->assertSame('apple-touch-icon.png', $s->resolveRequestPath('apple-touch-icon-240x240.png'));
        $this->assertSame('apple-touch-icon.png', $s->resolveRequestPath('apple-touch-icon-180x180-precomposed.png'));
        $this->assertSame('apple-touch-icon-120x120.png', $s->resolveRequestPath('apple-touch-icon-57x57.png'));

        $this->assertNull($s->resolveRequestPath('apple-touch-icon-0x0.png'), 'not a device size');
        $this->assertNull($s->resolveRequestPath('apple-touch-icon-120x152.png'), 'devices probe square sizes only');
        $this->assertSame('favicon.ico', $s->resolveRequestPath('FAVICON.ICO'), 'case-insensitive');
        $this->assertNull($s->resolveRequestPath('sites/favicon.ico'), 'no aliases by default');
        $this->assertNull($s->resolveRequestPath('apple-touch-icon.jpg'));
        $this->assertNull($s->resolveRequestPath('about-us'));
        $this->assertNull($s->resolveRequestPath(''));
    }

    public function testExtraPathsAddAliases()
    {
        Config::modify()->set(FaviconService::class, 'extra_paths', [
            'sites/favicon.ico' => 'favicon.ico',
            'sites/default/files/apple.png' => 'apple-touch-icon-precomposed.png',
            'loop.png' => 'loop.png',
        ]);
        $s = $this->service();
        $this->assertSame('favicon.ico', $s->resolveRequestPath('sites/favicon.ico'));
        $this->assertSame('apple-touch-icon.png', $s->resolveRequestPath('sites/default/files/apple.png'));
        $this->assertNull($s->resolveRequestPath('loop.png'), 'an alias to itself is ignored, not recursed');
    }

    public function testAliasCyclesDoNotRecurse()
    {
        Config::modify()->set(FaviconService::class, 'extra_paths', [
            'a.ico' => 'b.ico',
            'b.ico' => 'a.ico',
            'c.ico' => 'sites/favicon.ico',
            'sites/favicon.ico' => 'favicon.ico',
        ]);
        $s = $this->service();
        $this->assertNull($s->resolveRequestPath('a.ico'));
        $this->assertNull($s->resolveRequestPath('b.ico'));
        $this->assertNull($s->resolveRequestPath('c.ico'), 'aliases resolve against built-in names only, not other aliases');
        $this->assertSame('favicon.ico', $s->resolveRequestPath('sites/favicon.ico'));
    }

    # Variants

    public function testPngSourceProducesEveryVariantAtItsSize()
    {
        $s = $this->service();
        $expected = [
            'apple-touch-icon.png' => 180,
            'apple-touch-icon-120x120.png' => 120,
            'apple-touch-icon-152x152.png' => 152,
            'apple-touch-icon-167x167.png' => 167,
            'apple-touch-icon-180x180.png' => 180,
            'icon-192.png' => 192,
            'icon-512.png' => 512,
            'icon-maskable-512.png' => 512,
        ];
        $variants = $s->getVariants();
        foreach ($expected as $name => $size) {
            $this->assertArrayHasKey($name, $variants);
            $data = $s->getVariant($name);
            $this->assertSame('image/png', $data['type'], $name);
            $this->assertSame([$size, $size], ImageProcessor::dimensions($data['body']), $name);
        }
        $this->assertArrayNotHasKey('icon.svg', $variants, 'no SVG variant from a PNG source');

        $ico = $s->getVariant('favicon.ico');
        $this->assertSame('image/x-icon', $ico['type']);
        $this->assertSame([16, 32, 48], array_column(IcoWriter::parse($ico['body']), 'width'));
    }

    public function testTouchIconsAreOpaqueAndStandardIconsTransparent()
    {
        if (!ImageProcessor::hasGd()) {
            $this->markTestSkipped('pixel check reads with GD');
        }
        Config::modify()->set(FaviconService::class, 'background_color', '#00ff00');
        $alphaAt00 = function (string $png) {
            $im = imagecreatefromstring($png);
            return imagecolorsforindex($im, imagecolorat($im, 0, 0));
        };
        $touch = $alphaAt00($this->service()->getVariant('apple-touch-icon.png')['body']);
        $this->assertSame([0, 255, 0, 0], [$touch['red'], $touch['green'], $touch['blue'], $touch['alpha']]);
        $maskable = $alphaAt00($this->service()->getVariant('icon-maskable-512.png')['body']);
        $this->assertSame(0, $maskable['alpha']);
        $plain = $alphaAt00($this->service()->getVariant('icon-192.png')['body']);
        $this->assertSame(127, $plain['alpha']);
    }

    public function testConfiguredSizesAreUsed()
    {
        Config::modify()->set(FaviconService::class, 'ico_sizes', [16, 32, 64]);
        Config::modify()->set(FaviconService::class, 'touch_sizes', [76, 152]);
        $s = $this->service();
        $this->assertSame([16, 32, 64], array_column(IcoWriter::parse($s->getVariant('favicon.ico')['body']), 'width'));
        $this->assertArrayHasKey('apple-touch-icon-76x76.png', $s->getVariants());
        $this->assertArrayNotHasKey('apple-touch-icon-120x120.png', $s->getVariants());
        $this->assertSame('apple-touch-icon-76x76.png', $s->resolveRequestPath('apple-touch-icon-72x72.png'));
    }

    public function testSvgSourceWithRasteriser()
    {
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon.svg'));
        if (!SvgRasteriser::singleton()->getAvailable()) {
            $this->markTestSkipped('No SVG rasteriser (Imagick with SVG, or rsvg-convert) on this machine');
        }
        $s = $this->service();
        $svg = $s->getVariant('icon.svg');
        $this->assertSame('image/svg+xml', $svg['type']);
        $this->assertSame(file_get_contents($this->fixture('icon.svg')), $svg['body']);
        $this->assertSame([180, 180], ImageProcessor::dimensions($s->getVariant('apple-touch-icon.png')['body']));
        $this->assertSame([512, 512], ImageProcessor::dimensions($s->getVariant('icon-512.png')['body']));
        $this->assertCount(3, IcoWriter::parse($s->getVariant('favicon.ico')['body']));
    }

    /**
     * Each rasteriser on its own, so a machine that has both proves both paths.
     */
    public function testEachAvailableRasteriserRendersThePng()
    {
        $svg = file_get_contents($this->fixture('icon.svg'));
        $tried = 0;
        foreach (['imagick', 'rsvg-convert'] as $name) {
            Config::modify()->set(SvgRasteriser::class, 'rasterisers', [$name]);
            $r = SvgRasteriser::create();
            if ($r->getAvailable() !== $name) {
                continue;
            }
            $tried++;
            $png = $r->rasterise($svg, 128);
            $this->assertNotNull($png, $name);
            $this->assertSame([128, 128], ImageProcessor::dimensions($png), $name);
        }
        if (!$tried) {
            $this->markTestSkipped('No SVG rasteriser on this machine');
        }
    }

    public function testSvgSourceWithoutRasteriserDegradesToSvgOnly()
    {
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon.svg'));
        Config::modify()->set(SvgRasteriser::class, 'rasterisers', []);
        $s = $this->service();
        $this->assertFalse($s->canRasterise());
        $this->assertSame(['icon.svg', 'site.webmanifest'], array_keys($s->getVariants()));
        $this->assertNull($s->getVariant('favicon.ico'));
        $this->assertNull($s->getVariant('apple-touch-icon.png'));
        $this->assertNotNull($s->getVariant('icon.svg'));

        $tags = $s->getHeadTags();
        $this->assertStringContainsString('type="image/svg+xml"', $tags);
        $this->assertStringNotContainsString('favicon.ico', $tags);
        $this->assertStringNotContainsString('apple-touch-icon', $tags);
        $this->assertStringContainsString('cannot convert SVG to PNG', $s->getStatusMessage());

        $manifest = json_decode($s->getVariant('site.webmanifest')['body'], true);
        $this->assertSame(['image/svg+xml'], array_column($manifest['icons'], 'type'));
    }

    # SVG safety and failure handling

    private function writeTempSvg(string $svg): string
    {
        $path = tempnam(sys_get_temp_dir(), 'favsvg') . '.svg';
        file_put_contents($path, $svg);
        $this->tempFiles[] = $path;
        return $path;
    }

    /** @var string[] */
    private array $tempFiles = [];

    /**
     * An SVG that draws a local file into the canvas (the secret is the opaque PNG fixture).
     */
    private function evilSvg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="512" height="512" viewBox="0 0 512 512">'
            . '<image x="0" y="0" width="512" height="512" xlink:href="' . $this->fixture('icon.png') . '"/>'
            . '<rect style="fill:url(' . $this->fixture('icon.png') . ')" width="1" height="1"/>'
            . '</svg>';
    }

    public function testRasteriserOnlyEverSeesTheSanitisedSvg()
    {
        Injector::inst()->registerService(new FavFakeRasteriser(), SvgRasteriser::class);
        FavFakeRasteriser::$result = file_get_contents($this->fixture('icon.png'));
        Config::modify()->set(FaviconService::class, 'source', $this->writeTempSvg($this->evilSvg()));

        $this->assertNotNull($this->service()->getVariant('icon-192.png'));
        $this->assertCount(1, FavFakeRasteriser::$received);
        $this->assertStringNotContainsString($this->fixture('icon.png'), FavFakeRasteriser::$received[0]);
        # Changed on purpose with the allowlist rewrite (review round 2): an <image> without an embedded
        # bitmap is left out entirely; the rest of the drawing stays.
        $this->assertStringNotContainsString('<image', FavFakeRasteriser::$received[0]);
        $this->assertStringContainsString('<rect', FavFakeRasteriser::$received[0], 'the drawing itself stays');
        # icon.svg itself is served as stored: browsers do not load references from an <img> icon,
        # and the response carries a CSP sandbox
        $this->assertSame($this->evilSvg(), $this->service()->getVariant('icon.svg')['body']);
    }

    public function testRealRasterisersDoNotDrawReferencedLocalFiles()
    {
        $tried = 0;
        foreach (['rsvg-convert', 'imagick'] as $name) {
            Config::modify()->set(SvgRasteriser::class, 'rasterisers', [$name]);
            if (SvgRasteriser::create()->getAvailable() !== $name) {
                continue;
            }
            $tried++;
            # A fresh source per renderer, so the cached master of the previous one is not reused
            Config::modify()->set(FaviconService::class, 'source', $this->writeTempSvg($this->evilSvg() . "<!-- $name -->"));
            $png = $this->service()->getVariant('icon-192.png')['body'] ?? null;
            if ($png === null) {
                # A renderer that refuses an empty drawing is fine too: nothing leaked
                continue;
            }
            $im = imagecreatefromstring($png);
            $centre = imagecolorsforindex($im, imagecolorat($im, 96, 96));
            $this->assertSame(127, $centre['alpha'], "$name drew the referenced local file");
        }
        if (!$tried) {
            $this->markTestSkipped('No SVG rasteriser on this machine');
        }
    }

    public function testFailedGenerationIsCachedAndNotAdvertised()
    {
        Injector::inst()->registerService(new FavFakeRasteriser(), SvgRasteriser::class);
        FavFakeRasteriser::$result = null;
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon.svg'));
        $s = $this->service();

        $this->assertTrue($s->canRasterise(), 'a rasteriser is available, so the variants are offered at first');
        $this->assertNull($s->getVariant('favicon.ico'));
        $this->assertCount(1, FavFakeRasteriser::$received);

        # The failure is remembered: no retry, and the bitmap variants are withdrawn
        $this->assertFalse($s->canRasterise());
        $this->assertNull($s->getVariant('favicon.ico'));
        $this->assertNull($s->getVariant('apple-touch-icon.png'));
        $this->assertCount(1, FavFakeRasteriser::$received, 'not retried within failure_ttl');
        $this->assertSame(['icon.svg', 'site.webmanifest'], array_keys($s->getVariants()));
        $this->assertStringNotContainsString('favicon.ico', $s->getHeadTags());
        $manifest = json_decode($s->getVariant('site.webmanifest')['body'], true);
        $this->assertSame(['image/svg+xml'], array_column($manifest['icons'], 'type'));
    }

    public function testCorruptPngSourceSetsTheFailureMarker()
    {
        # Passes the PNG signature sniff, then fails to decode
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('corrupt.png'));
        $s = $this->service();
        $source = $s->getSource();
        $this->assertNotNull($source);
        $this->assertFalse($s->hasFailed($source));
        $this->assertNull($s->getVariant('icon-192.png'));
        $this->assertTrue($s->hasFailed($source), 'the exception path marks the source as failed');
        $this->assertFalse($s->canRasterise($source));
        $this->assertStringNotContainsString('favicon.ico', $s->getHeadTags());
    }

    public function testConcurrentRenderIsNotStarted()
    {
        Injector::inst()->registerService(new FavFakeRasteriser(), SvgRasteriser::class);
        FavFakeRasteriser::$result = file_get_contents($this->fixture('icon.png'));
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon.svg'));
        $s = $this->service();
        # Another request is rendering this source right now
        $busyKey = 'busy_' . FaviconService::GENERATOR_VERSION . '_' . $s->getSource()->getHash();
        $s->getCache()->set($busyKey, 1, 60);
        $this->assertNull($s->getVariant('icon-192.png'), 'fast 404 instead of a second render');
        $this->assertSame([], FavFakeRasteriser::$received);
        $this->assertFalse($s->hasFailed($s->getSource()), 'busy is not a failure');

        $s->getCache()->delete($busyKey);
        $this->assertNotNull($s->getVariant('icon-192.png'));
        $this->assertCount(1, FavFakeRasteriser::$received);
        $this->assertFalse((bool) $s->getCache()->get($busyKey), 'the marker is cleared after rendering');
    }

    public function testUnsafeSvgFailsClosed()
    {
        Injector::inst()->registerService(new FavFakeRasteriser(), SvgRasteriser::class);
        FavFakeRasteriser::$result = file_get_contents($this->fixture('icon.png'));
        Config::modify()->set(
            FaviconService::class,
            'source',
            $this->writeTempSvg('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>')
        );
        $this->assertNull($this->service()->getVariant('icon-192.png'));
        $this->assertSame([], FavFakeRasteriser::$received, 'never handed to a rasteriser');
    }

    # Manifest

    public function testManifestDefaultsAndOverrides()
    {
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        $data = $this->service()->getVariant('site.webmanifest');
        $this->assertSame('application/manifest+json', $data['type']);
        $json = json_decode($data['body'], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame('Example Site', $json['name']);
        $this->assertSame('Example Site', $json['short_name']);
        $this->assertSame('#ffffff', $json['background_color']);
        $this->assertSame('browser', $json['display']);
        $this->assertSame('/', $json['start_url']);
        $this->assertArrayNotHasKey('theme_color', $json, 'unset members are left out, not null');
        $this->assertSame(['192x192', '512x512', '512x512'], array_column($json['icons'], 'sizes'));
        $this->assertSame('maskable', $json['icons'][2]['purpose']);

        Config::modify()->set(FaviconService::class, 'theme_color', '#123456');
        Config::modify()->merge(FaviconService::class, 'manifest_fields', ['short_name' => 'Ex']);
        $json = json_decode($this->service()->getVariant('site.webmanifest')['body'], true);
        $this->assertSame('Ex', $json['short_name']);
        $this->assertSame('#123456', $json['theme_color']);
    }

    public function testManifestStartUrlFollowsTheBaseUrl()
    {
        Config::modify()->set(Director::class, 'alternate_base_url', '/sub/');
        $json = json_decode($this->service()->getVariant('site.webmanifest')['body'], true);
        $this->assertSame('/sub/', $json['start_url']);
        $this->assertSame('/sub/icon-192.png', $json['icons'][0]['src']);

        Config::modify()->merge(FaviconService::class, 'manifest_fields', ['start_url' => '/sub/?source=pwa']);
        $json = json_decode($this->service()->getVariant('site.webmanifest')['body'], true);
        $this->assertSame('/sub/?source=pwa', $json['start_url']);
    }

    public function testManifestCanBeDisabled()
    {
        Config::modify()->set(FaviconService::class, 'manifest', false);
        $this->assertArrayNotHasKey('site.webmanifest', $this->service()->getVariants());
        $this->assertNull($this->service()->getVariant('site.webmanifest'));
        $this->assertStringNotContainsString('rel="manifest"', $this->service()->getHeadTags());
    }

    # Versioning and tags

    public function testVersionFollowsSourceAndConfig()
    {
        $s = $this->service();
        $v1 = $s->getVersion();
        $this->assertSame($v1, $s->getVersion(), 'stable');
        Config::modify()->set(FaviconService::class, 'background_color', '#000000');
        $v2 = $s->getVersion();
        $this->assertNotSame($v1, $v2);
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('small.png'));
        $this->assertNotSame($v2, $s->getVersion());
    }

    public function testHeadTagsForPngSource()
    {
        Config::modify()->set(FaviconService::class, 'theme_color', '#abcdef');
        $s = $this->service();
        $v = $s->getVersion();
        $tags = $s->getHeadTags();
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico?v=' . $v . '" sizes="32x32">', $tags);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=' . $v . '">', $tags);
        $this->assertStringContainsString('<link rel="manifest" href="/site.webmanifest?v=' . $v . '">', $tags);
        $this->assertStringContainsString('<meta name="theme-color" content="#abcdef">', $tags);
        $this->assertStringNotContainsString('image/svg+xml', $tags);
    }
}
