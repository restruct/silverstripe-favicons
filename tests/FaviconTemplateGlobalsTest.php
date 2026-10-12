<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Tests\Stub\FavThrowingServiceLookup;
use Restruct\Favicons\View\FaviconTemplateGlobals;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\SSViewer;
use SilverStripe\View\ViewLayerData;

/**
 * $FaviconTags: the head tags for a project that places them itself.
 */
class FaviconTemplateGlobalsTest extends SapphireTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('FaviconTemplateGlobalsTest');
        $this->pinFaviconConfig();
        # Tags are only emitted for a generated set; a page render never generates one
        FaviconService::singleton()->warmUp();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * Render a template string the way each major does it: SSTemplateEngine on 6 (in
     * silverstripe/template-engine), SSViewer::fromString() on 5.
     */
    private function render(string $template): string
    {
        $model = SiteConfig::current_site_config();
        $engine = 'SilverStripe\\TemplateEngine\\SSTemplateEngine';
        if (class_exists($engine)) {
            return (string) Injector::inst()->create($engine)->renderString($template, ViewLayerData::create($model));
        }
        return (string) SSViewer::fromString($template)->process($model);
    }

    public function testTemplateGetsTheSameTagsUnescaped()
    {
        $tags = FaviconService::singleton()->getHeadTags();
        $this->assertStringContainsString('<link rel="icon"', $tags, 'precondition: a set exists');
        $this->assertSame('[' . $tags . ']', $this->render('[$FaviconTags]'));
    }

    public function testTemplateGetsTheTagsWithAutomaticInsertionOff()
    {
        # The use case: the project switched the automatic tags off and places them itself
        Config::modify()->set(FaviconService::class, 'frontend_tags', false);
        $this->assertStringContainsString('<link rel="apple-touch-icon"', $this->render('$FaviconTags'));
    }

    public function testNothingWithoutASource()
    {
        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertSame('[]', $this->render('[$FaviconTags]'));
    }

    public function testAnErrorNeverReachesTheRender()
    {
        Injector::inst()->registerService(new FavThrowingServiceLookup(), FaviconService::class);
        $this->assertSame('', FaviconTemplateGlobals::getFaviconTags()->getValue());
        $this->assertSame('[]', $this->render('[$FaviconTags]'));
    }
}
