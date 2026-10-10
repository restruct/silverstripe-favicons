<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\SiteConfig\SiteConfig;

class SiteConfigFaviconExtensionTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinFaviconConfig();
        # Field tests start from "nothing configured", so the status hint is predictable
        Config::modify()->set(FaviconService::class, 'source', null);
    }

    public function testRelationIsDeclaredAndOwned()
    {
        $schema = DataObject::getSchema();
        $this->assertSame(Image::class, $schema->hasOneComponent(SiteConfig::class, 'FaviconSource'));
        $this->assertContains('FaviconSource', SiteConfig::config()->get('owns'));
        $this->assertArrayHasKey('FaviconSourceID', $schema->databaseFields(SiteConfig::class));
    }

    public function testNoUploadFieldByDefault()
    {
        $fields = SiteConfig::current_site_config()->getCMSFields();
        $this->assertNull($fields->dataFieldByName('FaviconSource'));
    }

    public function testUploadFieldWhenAllowed()
    {
        if (!class_exists(UploadField::class)) {
            $this->markTestSkipped('silverstripe/asset-admin not installed');
        }
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        Config::modify()->set(FaviconService::class, 'upload_folder', 'site-icons');
        $fields = SiteConfig::current_site_config()->getCMSFields();
        /** @var UploadField $field */
        $field = $fields->dataFieldByName('FaviconSource');
        $this->assertInstanceOf(UploadField::class, $field);
        $this->assertSame(['png'], array_values($field->getAllowedExtensions()));
        $this->assertSame('site-icons', $field->getFolderName());
        $this->assertSame(1, $field->getAllowedMaxFileNumber());
        # Placed right after the tagline, on the main tab
        $names = array_map(fn($f) => $f->getName(), $fields->findTab('Root.Main')->Fields()->dataFields());
        $names = array_values($names);
        $this->assertSame(array_search('Tagline', $names) + 1, array_search('FaviconSource', $names));
        # The status hint tells the editor what is being served
        $this->assertStringContainsString('No favicon source configured', (string) $field->getDescription());
    }

    public function testUploadFieldAcceptsSvgWithSvgImages()
    {
        if (!class_exists(UploadField::class)) {
            $this->markTestSkipped('silverstripe/asset-admin not installed');
        }
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        Config::modify()->set(FaviconService::class, 'svg_images_class', Image::class);
        $field = SiteConfig::current_site_config()->getCMSFields()->dataFieldByName('FaviconSource');
        $this->assertSame(['png', 'svg'], array_values($field->getAllowedExtensions()));
    }
}
