<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Tests\Stub\FavSpyBackend;
use Restruct\Favicons\Tests\Stub\FavLockRecordingService;
use Restruct\Favicons\Tests\Stub\FavStoreDouble;
use Restruct\Favicons\Tests\Stub\FavThrowingTagsService;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

class FaviconServiceTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FaviconServiceTest');
        $this->pinFaviconConfig();
        FavSpyBackend::$widths = [];
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

    /**
     * The bytes a variant is served with, read the way the middleware reads them.
     */
    private function read(string $name): ?string
    {
        $data = $this->service()->openVariant($name);
        if ($data === null) {
            return null;
        }
        if (isset($data['stream'])) {
            $body = stream_get_contents($data['stream']);
            fclose($data['stream']);
            return $body;
        }
        return $data['body'];
    }

    private function makeUpload(string $fixture, bool $publish = true): File
    {
        $file = Image::create();
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

    private function store(): AssetStore
    {
        return Injector::inst()->get(AssetStore::class);
    }

    # Source resolution and validation

    public function testYamlSourceAsAbsolutePath()
    {
        $source = $this->service()->getSource();
        $this->assertNotNull($source);
        $this->assertSame(sha1_file($this->fixture('icon.png')), $source->getHash());
        $this->assertSame('file:' . $this->fixture('icon.png'), $source->getOrigin());
    }

    public function testYamlSourceAsModuleResource()
    {
        $path = $this->service()->resolveResourcePath('restruct/silverstripe-favicons:tests/fixtures/icon.png');
        $this->assertNotNull($path);
        $this->assertSame(realpath($this->fixture('icon.png')), realpath($path));
    }

    public function testYamlSourceAsProjectRelativePath()
    {
        # A path relative to the project root: the module's own fixture, reached through vendor/
        $path = $this->service()->resolveResourcePath('vendor/restruct/silverstripe-favicons/tests/fixtures/icon.png');
        $this->assertNotNull($path);
        $this->assertSame(realpath($this->fixture('icon.png')), realpath($path));
    }

    public function testUnresolvableYamlSourcesResolveToNothing()
    {
        $this->assertNull($this->service()->resolveResourcePath('no-such/module:icon.png'), 'unknown module');
        $this->assertNull($this->service()->resolveResourcePath('app/no-such-icon.png'), 'missing file');

        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertNull($this->service()->getSource());
        $this->assertSame([], $this->service()->getVariants());
        $this->assertSame('', $this->service()->getHeadTags());
    }

    public function testOnlySquarePngsOfAtLeast512AreAccepted()
    {
        $s = $this->service();
        $this->assertNull($s->checkPng((string) file_get_contents($this->fixture('icon.png'))));
        $this->assertNull($s->checkPng((string) file_get_contents($this->fixture('icon2.png'))));
        $this->assertStringContainsString('not a PNG', (string) $s->checkPng((string) file_get_contents($this->fixture('fake.png'))));
        $this->assertStringContainsString('not a PNG', (string) $s->checkPng("\xFF\xD8\xFF\xE0" . str_repeat("\0", 40)), 'JPEG');
        $this->assertStringContainsString('not a PNG', (string) $s->checkPng(''));
        $this->assertStringContainsString(
            '64x64 px; it must be at least 512x512',
            (string) $s->checkPng((string) file_get_contents($this->fixture('small.png')))
        );
        $this->assertStringContainsString(
            '600x300 px; it must be square',
            (string) $s->checkPng((string) file_get_contents($this->fixture('wide.png')))
        );
        # Only the header is read, so a header claiming 5000 px is enough to test the upper limit
        $huge = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 5000, 5000);
        $this->assertStringContainsString('at most 2048x2048', (string) $s->checkPng($huge));
        Config::modify()->set(FaviconService::class, 'max_source_size', 0);
        $this->assertNull($s->checkPng($huge), 'max_source_size 0 disables the upper limit');
    }

    public function testCheckFollowsTheCurrentConfigNotACachedVerdict()
    {
        # A header claiming 3000x3000 px: only the header is read for the check
        $path = TEMP_PATH . '/favicons-test-3000.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 3000, 3000) . str_repeat("\0", 20));
        try {
            Config::modify()->set(FaviconService::class, 'source', $path);
            $s = $this->service();
            $this->assertNull($s->getSource(), 'refused at the default max_source_size');
            $this->assertStringContainsString('at most 2048x2048', (string) $s->getSourceCandidates()[0]->getError());

            # Same file, cache entry already there: raising the limit accepts it at once
            Config::modify()->set(FaviconService::class, 'max_source_size', 4096);
            $this->assertNotNull($s->getSource());
        } finally {
            @unlink($path);
        }
    }

    public function testCheckOfAStoredFileFollowsTheCurrentConfig()
    {
        Config::modify()->set(FaviconService::class, 'max_source_size', 600);
        # Stored before allow_upload is on, so saving does not refuse it
        $this->makeUpload('icon1024.png');
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $s = $this->service();
        $this->assertStringStartsWith('file:', $s->getSource()->getOrigin(), 'the 1024 px upload is refused at 600');
        Config::modify()->set(FaviconService::class, 'max_source_size', 2048);
        $this->assertStringStartsWith('upload:', $s->getSource()->getOrigin(), 'and accepted once the limit is raised');
    }

    public function testUnusableYamlSourceIsSkippedWithAReason()
    {
        foreach (['fake.png' => 'not a PNG', 'small.png' => 'at least 512x512', 'wide.png' => 'must be square'] as $fixture => $reason) {
            Config::modify()->set(FaviconService::class, 'source', $this->fixture($fixture));
            $s = $this->service();
            $this->assertNull($s->getSource(), $fixture);
            $candidates = $s->getSourceCandidates();
            $this->assertCount(1, $candidates, $fixture);
            $this->assertStringContainsString($reason, (string) $candidates[0]->getError(), $fixture);
            $this->assertStringContainsString($reason, $s->getStatusMessage(), $fixture);
            $this->assertTrue($s->isDegraded(), $fixture);
            $this->assertSame('', $s->getHeadTags(), $fixture);
        }
    }

    public function testUploadOverridesYamlWhenAllowed()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $file = $this->makeUpload('icon2.png');
        $source = $this->service()->getSource();
        $this->assertSame($file->getHash(), $source->getHash());
        $this->assertStringStartsWith('upload:', $source->getOrigin());
    }

    public function testUploadIgnoredWhenNotAllowed()
    {
        $this->makeUpload('icon2.png');
        $source = $this->service()->getSource();
        $this->assertSame(sha1_file($this->fixture('icon.png')), $source->getHash(), 'YAML source wins');
    }

    public function testUnpublishedUploadIsIgnored()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $this->makeUpload('icon2.png', false);
        $this->assertStringStartsWith('file:', $this->service()->getSource()->getOrigin());
    }

    public function testUnusableUploadFallsBackToYamlAndSaysWhy()
    {
        # Stored while allow_upload was off, so neither the upload field nor the save validation
        # checked it (an import or a migration could do the same)
        $this->makeUpload('small.png');
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $s = $this->service();
        $this->assertStringStartsWith('file:', $s->getSource()->getOrigin());
        $status = $s->getStatusMessage();
        $this->assertStringContainsString('Skipped upload:favicon/small.png', $status);
        $this->assertStringContainsString('Serving all favicon variants from file:', $status);
        $this->assertTrue($s->isDegraded(), 'what is served is not what was uploaded');
    }

    public function testUploadExtensionsArePngOnly()
    {
        $this->assertSame(['png'], FaviconService::allowedUploadExtensions());
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
        $this->assertNull($s->resolveRequestPath('icon.svg'), 'no SVG variant any more');
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

    public function testEveryVariantIsProducedAtItsSize()
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
            $this->assertSame('image/png', $s->openVariant($name)['type'], $name);
            $this->assertSame([$size, $size], FaviconService::pngDimensions((string) $this->read($name)), $name);
        }

        $this->assertSame('image/x-icon', $s->openVariant('favicon.ico')['type']);
        $entries = IcoWriter::parse((string) $this->read('favicon.ico'));
        $this->assertSame([16, 32, 48], array_column($entries, 'width'));
    }

    public function testVariantsAreDrawnByTheInjectorImageBackend()
    {
        # Swap the framework's backend for a counting subclass, the way a project would swap in its own
        Injector::inst()->load([Image_Backend::class => [
            'class' => FavSpyBackend::class,
            'factory' => '%$SilverStripe\\Assets\\ImageBackendFactory',
        ]]);
        # The factory caches backends per file hash; a fresh one must not hand out earlier instances
        Injector::inst()->unregisterNamedObject('SilverStripe\\Assets\\ImageBackendFactory');

        $this->assertNotNull($this->read('icon-192.png'));
        # Every variant size went through the backend: 3 ICO entries, 4 touch sizes, 192, 512
        $drawn = array_values(array_unique(FavSpyBackend::$widths));
        sort($drawn);
        $this->assertSame([16, 32, 48, 120, 152, 167, 180, 192, 512], $drawn);

        # Done once per set: serving again reads the store, it does not redraw
        $count = count(FavSpyBackend::$widths);
        $this->assertNotNull($this->read('apple-touch-icon.png'));
        $this->assertNotNull($this->read('favicon.ico'));
        $this->assertCount($count, FavSpyBackend::$widths);
    }

    public function testOpaqueVariantsAreFilledAndPlainOnesStayTransparent()
    {
        Config::modify()->set(FaviconService::class, 'background_color', '#00ff00');
        # Pixel (0,0) of the fixture is transparent; the circle is #2c3e50
        $pixel = function (string $name, int $x, int $y) {
            $im = imagecreatefromstring((string) $this->read($name));
            $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
            return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
        };
        $this->assertSame([0, 255, 0, 0], $pixel('apple-touch-icon.png', 0, 0), 'touch icon: background colour');
        $this->assertSame(127, $pixel('icon-192.png', 0, 0)[3], 'icon-192: transparent');
        $this->assertSame([44, 62, 80, 0], $pixel('icon-192.png', 96, 96), 'icon-192: the icon itself');

        # The maskable icon has a 10% margin: at the middle of the left edge the circle of the full-size
        # icon would already be drawn, here it is still background
        $this->assertSame([44, 62, 80, 0], $pixel('icon-512.png', 4, 256), 'no margin on icon-512');
        $this->assertSame([0, 255, 0, 0], $pixel('icon-maskable-512.png', 4, 256), 'margin on the maskable icon');
        $this->assertSame([44, 62, 80, 0], $pixel('icon-maskable-512.png', 256, 256));
    }

    public function testConfiguredSizesAreUsed()
    {
        Config::modify()->set(FaviconService::class, 'ico_sizes', [16, 32, 64]);
        Config::modify()->set(FaviconService::class, 'touch_sizes', [76, 152]);
        $s = $this->service();
        $this->assertSame([16, 32, 64], array_column(IcoWriter::parse((string) $this->read('favicon.ico')), 'width'));
        $this->assertArrayHasKey('apple-touch-icon-76x76.png', $s->getVariants());
        $this->assertArrayNotHasKey('apple-touch-icon-120x120.png', $s->getVariants());
        $this->assertSame([76, 76], FaviconService::pngDimensions((string) $this->read('apple-touch-icon-76x76.png')));
        $this->assertSame('apple-touch-icon-76x76.png', $s->resolveRequestPath('apple-touch-icon-72x72.png'));
    }

    # Storage

    public function testVariantsAreStoredPubliclyInTheAssetStore()
    {
        $s = $this->service();
        $source = $s->getSource();
        $set = $s->ensureSet($source);
        $this->assertNotNull($set);
        $setId = $s->getSetId($source);
        $this->assertSame($setId, $set['id']);
        $store = $this->store();

        $original = '_favicons/' . $setId . '/icon.png';
        $this->assertTrue($store->exists($original, $set['src']));
        $this->assertSame(sha1($store->getAsString($original, $set['src'])), $set['src']);
        $this->assertSame([512, 512], FaviconService::pngDimensions((string) $store->getAsString($original, $set['src'])));
        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $store->getVisibility($original, $set['src']));

        foreach (['apple-touch-icon.png', 'icon-192.png', 'icon-maskable-512.png'] as $name) {
            $variant = $s->variantName($s->getVariants()[$name]);
            $this->assertTrue($store->exists($original, $set['src'], $variant), $name);
            $this->assertSame(
                $store->getAsString($original, $set['src'], $variant),
                $this->read($name),
                $name . ' is served from the store'
            );
            $url = $store->getAsURL($original, $set['src'], $variant, false);
            $this->assertStringContainsString('/_favicons/' . $setId . '/icon__' . $variant . '.png', $url);
        }

        $ico = '_favicons/' . $setId . '/favicon.ico';
        $this->assertTrue($store->exists($ico, $set['ico']));
        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $store->getVisibility($ico, $set['ico']));
        $this->assertSame($store->getAsString($ico, $set['ico']), $this->read('favicon.ico'));
    }

    public function testYamlSourceCreatesNoFileRecord()
    {
        $before = File::get()->count();
        $this->assertNotNull($this->read('favicon.ico'));
        $this->assertNotNull($this->read('apple-touch-icon.png'));
        $this->assertSame($before, File::get()->count(), 'no File (or Folder) record for the source or its variants');
        $this->assertNull(File::get()->filter('FileFilename:StartsWith', '_favicons')->first());
    }

    public function testPreviousSetIsDeletedWhenTheSourceChanges()
    {
        $s = $this->service();
        $store = $this->store();
        $first = $s->ensureSet($s->getSource());
        $firstVariant = $s->variantName($s->getVariants()['apple-touch-icon.png']);
        $firstFile = '_favicons/' . $first['id'] . '/icon.png';
        $this->assertTrue($store->exists($firstFile, $first['src'], $firstVariant));

        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon2.png'));
        $second = $s->ensureSet($s->getSource());
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertTrue($store->exists('_favicons/' . $second['id'] . '/icon.png', $second['src']));

        $this->assertFalse($store->exists($firstFile, $first['src']), 'old original removed');
        $this->assertFalse($store->exists($firstFile, $first['src'], $firstVariant), 'old variants removed');
        $this->assertFalse($store->exists('_favicons/' . $first['id'] . '/favicon.ico', $first['ico']), 'old ICO removed');
        $this->assertSame([$second['id']], array_keys($s->getSets()), 'only the current set is registered');

        # A config change is a new set too, and also replaces the previous one
        Config::modify()->set(FaviconService::class, 'background_color', '#000000');
        $third = $s->ensureSet($s->getSource());
        $this->assertNotSame($second['id'], $third['id']);
        $this->assertFalse($store->exists('_favicons/' . $second['id'] . '/icon.png', $second['src']));
        $this->assertSame([$third['id']], array_keys($s->getSets()));
    }

    public function testFileRemovedFromTheStoreIsRegenerated()
    {
        $s = $this->service();
        $set = $s->ensureSet($s->getSource());
        $this->store()->delete('_favicons/' . $set['id'] . '/icon.png', $set['src']);
        $this->assertSame(
            [192, 192],
            FaviconService::pngDimensions((string) $this->read('icon-192.png')),
            'served again after the set was found incomplete'
        );
    }

    public function testUndecodablePngIsRememberedAndNotAdvertised()
    {
        # The header is a valid 512x512 PNG, so it passes the check; the image data is cut off
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('truncated.png'));
        $s = $this->service();
        $source = $s->getSource();
        $this->assertNotNull($source, 'passes the header check');
        $this->assertFalse($s->hasFailed($source));

        $this->assertNull($s->openVariant('icon-192.png'));
        $this->assertTrue($s->hasFailed($source), 'the failure is remembered');
        $this->assertSame(['site.webmanifest'], array_keys($s->getVariants()), 'image variants withdrawn');
        $this->assertNull($s->openVariant('favicon.ico'));
        $tags = $s->getHeadTags();
        $this->assertStringNotContainsString('favicon.ico', $tags);
        $this->assertStringNotContainsString('apple-touch-icon', $tags);
        $manifest = json_decode((string) $this->read('site.webmanifest'), true);
        $this->assertArrayNotHasKey('icons', $manifest);
        $this->assertStringContainsString('could not read', $s->getStatusMessage());
        $this->assertTrue($s->isDegraded());
    }

    # Manifest

    public function testManifestDefaultsAndOverrides()
    {
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();

        $data = $this->service()->openVariant('site.webmanifest');
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
        $json = json_decode((string) $this->read('site.webmanifest'), true);
        $this->assertSame('Ex', $json['short_name']);
        $this->assertSame('#123456', $json['theme_color']);
    }

    public function testManifestStartUrlFollowsTheBaseUrl()
    {
        Config::modify()->set(Director::class, 'alternate_base_url', '/sub/');
        $json = json_decode((string) $this->read('site.webmanifest'), true);
        $this->assertSame('/sub/', $json['start_url']);
        $this->assertSame('/sub/icon-192.png', $json['icons'][0]['src']);

        Config::modify()->merge(FaviconService::class, 'manifest_fields', ['start_url' => '/sub/?source=pwa']);
        $json = json_decode((string) $this->read('site.webmanifest'), true);
        $this->assertSame('/sub/?source=pwa', $json['start_url']);
    }

    public function testManifestCanBeDisabled()
    {
        Config::modify()->set(FaviconService::class, 'manifest', false);
        $this->assertArrayNotHasKey('site.webmanifest', $this->service()->getVariants());
        $this->assertNull($this->service()->openVariant('site.webmanifest'));
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
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon2.png'));
        $this->assertNotSame($v2, $s->getVersion());
    }

    public function testHeadTags()
    {
        Config::modify()->set(FaviconService::class, 'theme_color', '#abcdef');
        $s = $this->service();
        $s->warmUp();
        $v = $s->getVersion();
        $tags = $s->getHeadTags();
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico?v=' . $v . '" sizes="32x32">', $tags);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=' . $v . '">', $tags);
        $this->assertStringContainsString('<link rel="manifest" href="/site.webmanifest?v=' . $v . '">', $tags);
        $this->assertStringContainsString('<meta name="theme-color" content="#abcdef">', $tags);
        $this->assertStringNotContainsString('image/svg+xml', $tags);
    }

    # Page renders never generate, and never fail

    public function testTagsNeedACompletedSetAndNeverGenerateOne()
    {
        $s = $this->service();
        $this->assertSame('', $s->getHeadTags(), 'no tags before the set exists');
        $this->assertSame([], $s->getSets(), 'rendering the tags generated nothing');
        $this->assertStringContainsString('generated on the next dev/build or icon request', $s->getStatusMessage());
        $this->assertSame([], $s->getSets(), 'nor did the status message');

        # A probe generates it; from then on the tags are there
        $this->assertNotNull($this->read('favicon.ico'));
        $this->assertStringContainsString('rel="apple-touch-icon"', $s->getHeadTags());
    }

    public function testATagErrorIsSwallowed()
    {
        $broken = new FavThrowingTagsService();
        $this->assertSame('', $broken->getHeadTags(), 'logged, not thrown');
    }

    public function testRegistryIsSharedAcrossReadingModes()
    {
        # One filesystem pool for CLI and web: CacheFactory would pick a different adapter per SAPI
        # on Silverstripe 5 (PhpFiles with opcache, Filesystem without) and wrap it per stage
        $spec = Config::inst()->get(Injector::class, 'Psr\\SimpleCache\\CacheInterface.RestructFavicons');
        $this->assertSame('Symfony\\Component\\Cache\\Psr16Cache', $spec['class'] ?? null, 'the shipped service is a plain pool');
        $this->assertArrayNotHasKey('factory', $spec);
        $this->assertSame(
            'Symfony\\Component\\Cache\\Adapter\\FilesystemAdapter',
            Config::inst()->get(Injector::class, 'RestructFaviconsCachePool')['class'] ?? null
        );

        # dev/build and the CMS run in Stage, pages in Live, the icon middleware before any reading
        # mode is set: all must see the same registry
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            $this->service()->warmUp();
        });
        Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::LIVE);
            $this->assertNotNull($this->service()->getCompletedSet(), 'Live sees the set built in Stage');
        });
        Versioned::withVersionedMode(function () {
            Versioned::set_reading_mode('');
            $this->assertNotNull($this->service()->getCompletedSet(), 'nor does a request without a reading mode miss it');
        });
    }

    public function testFailingDeleteOfThePreviousSetDoesNotBreakAnything()
    {
        $s = $this->service();
        $first = $s->ensureSet($s->getSource());
        FavStoreDouble::install();
        FavStoreDouble::$throwOnDelete = true;

        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon2.png'));
        $second = $s->ensureSet($s->getSource());
        $this->assertNotNull($second, 'the new set is built although the old one cannot be deleted');
        $this->assertSame([$second['id']], array_keys($s->getSets()), 'the registry is written anyway');
        $this->assertNotSame('', $s->getHeadTags());
        $this->assertNotNull($this->read('favicon.ico'));
        $this->assertNotSame($first['id'], $second['id']);
    }

    public function testBuildLockPreventsAParallelBuild()
    {
        $s = $this->service();
        $source = $s->getSource();
        $lock = $s->buildLockPath($s->getSetId($source));
        # Another request is building this set right now
        touch($lock);
        try {
            $this->assertNull($s->ensureSet($source), 'another request is building it');
            $this->assertNull($this->read('icon-192.png'), 'so the probe 404s instead of decoding again');
            $this->assertFalse($s->hasFailed($source), 'busy is not a failure');
            $this->assertSame([], $s->getSets());
        } finally {
            @unlink($lock);
        }
        $this->assertNotNull($s->ensureSet($source));
        $this->assertFileDoesNotExist($lock, 'the lock is removed after the build');
    }

    public function testStaleBuildLockIsTakenOver()
    {
        $s = $this->service();
        $source = $s->getSource();
        $lock = $s->buildLockPath($s->getSetId($source));
        # Left by a worker that was killed, longer ago than busy_ttl
        touch($lock, time() - 200);
        try {
            $this->assertNotNull($s->ensureSet($source));
        } finally {
            @unlink($lock);
        }
    }

    public function testBuildLockIsHeldWhileBuilding()
    {
        $s = new FavLockRecordingService();
        $source = $s->getSource();
        $this->assertNotNull($s->ensureSet($source));
        $this->assertSame([true], $s->lockHeldDuringBuild);
        $this->assertFileDoesNotExist($s->buildLockPath($s->getSetId($source)));
    }

    public function testSourceIsDecodedOnceAndShrunkBeforeDrawing()
    {
        Injector::inst()->load([Image_Backend::class => [
            'class' => FavSpyBackend::class,
            'factory' => '%$SilverStripe\\Assets\\ImageBackendFactory',
        ]]);
        Injector::inst()->unregisterNamedObject('SilverStripe\\Assets\\ImageBackendFactory');
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon1024.png'));

        $this->assertNotNull($this->service()->ensureSet($this->service()->getSource()));
        $this->assertSame(1, count(array_keys(FavSpyBackend::$widths, 1024)), 'the 1024 px source is decoded once');
        # Every image a backend received: that decode, the shrunk source (stored as icon.png), and
        # the 10 distinct variants drawn (7 PNGs: apple-touch-icon.png and the 180x180 one are the
        # same variant; 3 ICO entries). A backend decoding the stored copy again for each variant
        # would add one 512 px entry per variant.
        $this->assertCount(12, FavSpyBackend::$widths, json_encode(FavSpyBackend::$widths));
        $this->assertSame([], array_filter(FavSpyBackend::$widths, fn($w) => $w > 512 && $w !== 1024), 'nothing larger than needed is drawn');
        $this->assertSame([512, 512], FaviconService::pngDimensions((string) $this->read('icon-512.png')));
        $s = $this->service();
        $set = $s->getCompletedSet();
        $this->assertSame(
            [512, 512],
            FaviconService::pngDimensions((string) $s->getStore()->getAsString($s->setPath($set['id'], 'icon.png'), $set['src'])),
            'the stored copy is the shrunk source'
        );
    }

    public function testMetadataOfTheSourceIsNotPublished()
    {
        Config::modify()->set(FaviconService::class, 'source', $this->fixture('icon-meta.png'));
        $s = $this->service();
        $set = $s->ensureSet($s->getSource());
        $stored = (string) $s->getStore()->getAsString($s->setPath($set['id'], 'icon.png'), $set['src']);
        $this->assertStringStartsWith("\x89PNG", $stored);
        $this->assertStringNotContainsString('private-author-note', $stored, 'the stored copy is re-encoded, not copied');
    }

    public function testAnotherRequestsUnfinishedSetIsNotDeleted()
    {
        $s = $this->service();
        # Another request is building a set for another source right now
        $s->getCache()->set('sets', ['otherset00000000' => ['src' => 'x', 'ico' => null, 'done' => false]]);
        $lock = $s->buildLockPath('otherset00000000');
        touch($lock);
        try {
            $set = $s->ensureSet($s->getSource());
            $this->assertEqualsCanonicalizing([$set['id'], 'otherset00000000'], array_keys($s->getSets()));
        } finally {
            @unlink($lock);
        }

        # Once that request is gone (no lock), its leftover goes, also on the done-set path
        $s->ensureSet($s->getSource());
        $this->assertSame([$set['id']], array_keys($s->getSets()));
    }

    public function testSetRemovedWhileBuildingIsNotAFailure()
    {
        $s = $this->service();
        $source = $s->getSource();
        FavStoreDouble::install();
        # The stored copy disappears underneath the build (another request's cleanup)
        FavStoreDouble::$vanished = [$s->setPath($s->getSetId($source), 'icon.png')];
        $this->assertNull($s->ensureSet($source));
        $this->assertFalse($s->hasFailed($source), 'not reported as an undecodable source');

        FavStoreDouble::$vanished = [];
        $this->assertNotNull($s->ensureSet($source), 'the next request builds it');
    }
}
