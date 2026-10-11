<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Extensions\FaviconHeadTagsExtension;
use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Tests\Stub\FavTestController;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Security\Security;

/**
 * The <link> tags land in the rendered <head> of frontend, CMS and Security pages.
 */
class HeadTagsTest extends FunctionalTest
{
    use PinsFaviconConfig;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        # Rendering the tags generates the icon set into the AssetStore
        TestAssetStore::activate('HeadTagsTest');
        $this->pinFaviconConfig();
        # Prepended: a merge would append the rule after the CMS catch-all ($URLSegment//$Action),
        # which would claim the URL first and 404 it as a missing page.
        Config::modify()->set(
            Director::class,
            'rules',
            ['favtest//$Action' => FavTestController::class] + (array) Director::config()->get('rules')
        );
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function assertHasIconTags(string $html, string $where): void
    {
        $v = FaviconService::singleton()->getVersion();
        $head = substr($html, 0, (int) stripos($html, '</head>'));
        $this->assertNotSame('', $head, "$where: no </head> found");
        $this->assertStringContainsString('<link rel="icon" href="/favicon.ico?v=' . $v . '"', $head, $where);
        $this->assertStringContainsString('<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=' . $v . '"', $head, $where);
        $this->assertStringContainsString('<link rel="manifest"', $head, $where);
        $this->assertSame(1, substr_count($html, 'rel="apple-touch-icon"'), "$where: tags inserted once");
    }

    public function testExtensionIsAppliedToTheThreeControllerTypes()
    {
        $this->assertTrue(LeftAndMain::has_extension(FaviconHeadTagsExtension::class));
        $this->assertTrue(Security::has_extension(FaviconHeadTagsExtension::class));
        if (class_exists(ContentController::class)) {
            $this->assertTrue(ContentController::has_extension(FaviconHeadTagsExtension::class));
        }
    }

    public function testFrontend()
    {
        if (!class_exists(ContentController::class)) {
            $this->markTestSkipped('silverstripe/cms not installed');
        }
        $response = $this->get('favtest');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertHasIconTags($response->getBody(), 'frontend');
    }

    public function testFrontendTagsCanBeSwitchedOff()
    {
        if (!class_exists(ContentController::class)) {
            $this->markTestSkipped('silverstripe/cms not installed');
        }
        Config::modify()->set(FaviconService::class, 'frontend_tags', false);
        $this->assertStringNotContainsString('apple-touch-icon', $this->get('favtest')->getBody());
    }

    public function testCmsBackend()
    {
        $this->logInWithPermission('ADMIN');
        $response = $this->get('admin/settings');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertHasIconTags($response->getBody(), 'admin');

        Config::modify()->set(FaviconService::class, 'admin_tags', false);
        $this->assertStringNotContainsString('apple-touch-icon', $this->get('admin/settings')->getBody());
    }

    public function testSecurityLoginPage()
    {
        $response = $this->get('Security/login');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertHasIconTags($response->getBody(), 'security');
    }

    public function testSecurityTagsCanBeSwitchedOff()
    {
        Config::modify()->set(FaviconService::class, 'security_tags', false);
        $this->assertStringNotContainsString('apple-touch-icon', $this->get('Security/login')->getBody());
    }

    /**
     * Without login-forms, Security renders through a Page controller around a throwaway page
     * (URLSegment 'Security', negative ID). That must count as a Security page, so security_tags
     * still decides, not frontend_tags.
     */
    public function testSecurityRenderedThroughAPageControllerIsSecurity()
    {
        if (!class_exists(ContentController::class)) {
            $this->markTestSkipped('silverstripe/cms not installed');
        }
        $holder = SiteTree::create(['URLSegment' => 'Security']);
        $holder->ID = -42;
        $controller = ContentController::create($holder);
        $this->assertSame('security', $this->contextFor($controller));

        $page = SiteTree::create(['URLSegment' => 'Security']);
        $page->ID = 7;
        $controller = ContentController::create($page);
        $this->assertSame('frontend', $this->contextFor($controller), 'a real page that happens to be called Security');
    }

    private function contextFor($controller): string
    {
        $extension = new FaviconHeadTagsExtension();
        $extension->setOwner($controller);
        return $extension->getContext();
    }

    public function testSecurityViaPageClassHonoursSecurityTags()
    {
        if (!class_exists(SiteTree::class)) {
            $this->markTestSkipped('silverstripe/cms not installed');
        }
        # Force the page_class rendering path, whatever the host has (login-forms removes page_class
        # per request, so this only exercises the path where login-forms is absent).
        Config::modify()->set(Security::class, 'page_class', SiteTree::class);
        Config::modify()->set(FaviconService::class, 'security_tags', false);
        $body = $this->get('Security/login')->getBody();
        $this->assertStringNotContainsString('apple-touch-icon', $body);
    }

    public function testNoTagsWithoutSource()
    {
        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertStringNotContainsString('rel="icon"', $this->get('Security/login')->getBody());
    }
}
