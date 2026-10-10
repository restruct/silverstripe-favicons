<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Generator\ImageProcessor;
use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

class FaviconServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FaviconServiceTest');
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon.png'));
        Config::modify()->set(FaviconService::class, 'allow_upload', false);
        # Pretend svg-images is absent unless a test says otherwise, whatever the host has installed
        Config::modify()->set(FaviconService::class, 'svg_images_class', 'Restruct\\Favicons\\Tests\\NoSuchClass');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
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
        $this->assertArrayNotHasKey('theme_color', $json, 'unset members are left out, not null');
        $this->assertSame(['192x192', '512x512', '512x512'], array_column($json['icons'], 'sizes'));
        $this->assertSame('maskable', $json['icons'][2]['purpose']);

        Config::modify()->set(FaviconService::class, 'theme_color', '#123456');
        Config::modify()->merge(FaviconService::class, 'manifest_fields', ['short_name' => 'Ex']);
        $json = json_decode($this->service()->getVariant('site.webmanifest')['body'], true);
        $this->assertSame('Ex', $json['short_name']);
        $this->assertSame('#123456', $json['theme_color']);
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
