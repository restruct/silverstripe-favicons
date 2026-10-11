<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Tests\Stub\FavAdmintweaksStub;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * restruct/silverstripe-admintweaks' SiteConfig.FavIcon and ThemeBrowserColor as fallbacks, with a
 * stub extension declaring the same fields (that module is not a dependency).
 */
class AdmintweaksFallbackTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected static $required_extensions = [
        SiteConfig::class => [FavAdmintweaksStub::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('AdmintweaksFallbackTest');
        $this->pinFaviconConfig();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function publishedFile(string $fixture, string $folder): File
    {
        $file = Image::create();
        $file->setFromLocalFile(__DIR__ . '/fixtures/' . $fixture, $folder . '/' . $fixture);
        $file->write();
        $file->publishSingle();
        return $file;
    }

    private function setSiteConfig(array $values): void
    {
        $config = SiteConfig::current_site_config();
        foreach ($values as $field => $value) {
            $config->$field = $value;
        }
        $config->write();
    }

    private function origin(): ?string
    {
        $source = FaviconService::singleton()->getSource();
        return $source ? $source->getOrigin() : null;
    }

    public function testAdmintweaksIconIsDetected()
    {
        $this->assertTrue(FaviconService::singleton()->hasAdmintweaksIcon());
    }

    public function testPrecedenceUploadThenAdmintweaksThenYaml()
    {
        $this->assertStringStartsWith('file:', $this->origin(), 'only YAML set');

        $this->setSiteConfig(['FavIconID' => $this->publishedFile('icon2.png', 'logos')->ID]);
        $this->assertSame('admintweaks:logos/icon2.png', $this->origin(), 'admintweaks FavIcon beats YAML');

        $this->setSiteConfig(['FaviconSourceID' => $this->publishedFile('icon.png', 'favicon')->ID]);
        $this->assertSame('admintweaks:logos/icon2.png', $this->origin(), 'the upload only counts with allow_upload');

        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $this->assertSame('upload:favicon/icon.png', $this->origin(), 'own upload beats admintweaks');

        Config::modify()->set(FaviconService::class, 'allow_upload', false);
        Config::modify()->set(FaviconService::class, 'use_admintweaks', false);
        $this->assertStringStartsWith('file:', $this->origin(), 'use_admintweaks: false ignores it');
    }

    public function testUnusableAdmintweaksIconFallsThrough()
    {
        # admintweaks accepts any image type and size; a small logo must not become the favicon
        $this->setSiteConfig(['FavIconID' => $this->publishedFile('small.png', 'logos')->ID]);
        $s = FaviconService::singleton();
        $this->assertStringStartsWith('file:', $this->origin());
        $this->assertStringContainsString('Skipped admintweaks:logos/small.png', $s->getStatusMessage());
    }

    public function testThemeBrowserColorIsTheFallbackThemeColour()
    {
        $s = FaviconService::singleton();
        $this->assertNull($s->getThemeColor());

        $this->setSiteConfig(['ThemeBrowserColor' => '#336699']);
        $this->assertSame('#336699', $s->getThemeColor());
        $this->assertStringContainsString('<meta name="theme-color" content="#336699">', $s->getHeadTags());
        $this->assertSame('#336699', json_decode($s->buildManifest(), true)['theme_color']);

        Config::modify()->set(FaviconService::class, 'theme_color', '#000000');
        $this->assertSame('#000000', $s->getThemeColor(), 'configured theme_color wins');

        Config::modify()->set(FaviconService::class, 'theme_color', null);
        Config::modify()->set(FaviconService::class, 'use_admintweaks', false);
        $this->assertNull($s->getThemeColor(), 'use_admintweaks: false ignores it');

        Config::modify()->set(FaviconService::class, 'use_admintweaks', true);
        $this->setSiteConfig(['ThemeBrowserColor' => 'blue']);
        $this->assertNull($s->getThemeColor(), 'only a hex colour is used');
    }
}
