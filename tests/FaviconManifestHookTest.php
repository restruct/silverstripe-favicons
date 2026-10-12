<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Tests\Stub\FavManifestExtension;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * The updateManifestData extension hook: a project extension can add, change and remove manifest
 * members, and the ?v= cache-buster follows.
 */
class FaviconManifestHookTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected static $required_extensions = [
        FaviconService::class => [FavManifestExtension::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FaviconManifestHookTest');
        $this->pinFaviconConfig();
        FavManifestExtension::$active = false;
        FavManifestExtension::$sources = [];
        $config = SiteConfig::current_site_config();
        $config->Title = 'Example Site';
        $config->write();
    }

    protected function tearDown(): void
    {
        FavManifestExtension::$active = false;
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testExtensionCanAdjustTheManifest()
    {
        $s = FaviconService::singleton();
        $this->assertTrue($s->hasExtension(FavManifestExtension::class));
        $before = $s->getManifestData();
        $versionBefore = $s->getVersion();
        $this->assertSame('browser', $before['display']);
        $this->assertSame('#ffffff', $before['background_color']);
        $this->assertArrayNotHasKey('id', $before);

        FavManifestExtension::$active = true;
        $after = json_decode($s->buildManifest(), true);
        $this->assertSame('/?pwa', $after['id'], 'a member is added');
        $this->assertSame('standalone', $after['display'], 'a member is changed');
        $this->assertArrayNotHasKey('background_color', $after, 'a member set to null is left out');
        $this->assertSame('Example Site', $after['name'], 'the rest is untouched');
        $this->assertCount(3, $after['icons']);

        # The hook gets the source the manifest is built for
        $this->assertNotEmpty(FavManifestExtension::$sources);
        $this->assertSame($s->getSource()->getHash(), FavManifestExtension::$sources[0]->getHash());

        # The extended manifest is part of the version, so cached copies are replaced
        $this->assertNotSame($versionBefore, $s->getVersion());
    }
}
