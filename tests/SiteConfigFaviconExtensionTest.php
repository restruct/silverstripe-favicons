<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Forms\IconUploadValidator;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Upload_Validator;
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
        TestAssetStore::activate('SiteConfigFaviconExtensionTest');
        $this->pinFaviconConfig();
        # Field tests start from "nothing configured", so the status hint is predictable
        Config::modify()->set(FaviconService::class, 'source', null);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function uploadField(): UploadField
    {
        if (!class_exists(UploadField::class)) {
            $this->markTestSkipped('silverstripe/asset-admin not installed');
        }
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        /** @var UploadField $field */
        $field = SiteConfig::current_site_config()->getCMSFields()->dataFieldByName('FaviconSource');
        return $field;
    }

    /**
     * A $_FILES-style entry for a fixture, as the upload field hands it to its validator.
     */
    private function tmpFile(string $fixture, ?string $name = null): array
    {
        $path = __DIR__ . '/fixtures/' . $fixture;
        return ['name' => $name ?? $fixture, 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path)];
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
        $this->assertStringContainsString('No usable favicon source', (string) $field->getDescription());
    }

    public function testLibraryFilesCannotBeAttached()
    {
        # Choosing an existing file would bypass the upload validator
        $this->assertFalse($this->uploadField()->getAttachEnabled());
        $this->assertTrue($this->uploadField()->getUploadEnabled());
    }

    public function testUploadValidatorRefusesUnusablePngs()
    {
        $upload = $this->uploadField()->getUpload();
        $validator = $upload->getValidator();
        $this->assertInstanceOf(IconUploadValidator::class, $validator);
        # The field's own validator (extension and size checks, mimevalidator when installed) is kept
        $this->assertInstanceOf(Upload_Validator::class, $validator->getInner());
        $this->assertNotInstanceOf(IconUploadValidator::class, $validator->getInner());

        # The fixtures are not real uploads
        Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', false);
        $this->assertTrue($upload->validate($this->tmpFile('icon.png')), implode(' ', $upload->getErrors()));

        # fake.png is refused by silverstripe/mimevalidator first when installed, by the PNG check otherwise
        foreach (['small.png' => 'at least 512x512', 'wide.png' => 'must be square', 'fake.png' => ''] as $fixture => $reason) {
            $upload->clearErrors();
            $validator->clearErrors();
            $this->assertFalse($upload->validate($this->tmpFile($fixture)), $fixture);
            $this->assertStringContainsString($reason, implode(' ', $upload->getErrors()), $fixture);
        }

        # The inner validator still refuses other extensions, before the PNG check runs
        $upload->clearErrors();
        $this->assertFalse($upload->validate($this->tmpFile('icon.png', 'icon.svg')));
        $this->assertStringContainsString('is not allowed', implode(' ', $upload->getErrors()));
    }

    public function testSavingAnUnusableIconIsRefused()
    {
        Config::modify()->set(FaviconService::class, 'allow_upload', true);
        $small = Image::create();
        $small->setFromLocalFile(__DIR__ . '/fixtures/small.png', 'favicon/small.png');
        $small->write();
        $config = SiteConfig::current_site_config();
        $config->FaviconSourceID = $small->ID;
        $result = $config->validate();
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('at least 512x512', json_encode($result->getMessages()));

        $good = Image::create();
        $good->setFromLocalFile(__DIR__ . '/fixtures/icon.png', 'favicon/icon.png');
        $good->write();
        $config->FaviconSourceID = $good->ID;
        $this->assertTrue($config->validate()->isValid());

        # Without allow_upload the relation is not used, so it is not policed either
        Config::modify()->set(FaviconService::class, 'allow_upload', false);
        $config->FaviconSourceID = $small->ID;
        $this->assertTrue($config->validate()->isValid());
    }
}
