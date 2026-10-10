<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Extensions\FaviconHeadTagsExtension;
use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Tests\Stub\FavTestController;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Controllers\ContentController;
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
        $this->pinFaviconConfig();
        # Prepended: a merge would append the rule after the CMS catch-all ($URLSegment//$Action),
        # which would claim the URL first and 404 it as a missing page.
        Config::modify()->set(
            Director::class,
            'rules',
            ['favtest//$Action' => FavTestController::class] + (array) Director::config()->get('rules')
        );
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

    public function testNoTagsWithoutSource()
    {
        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertStringNotContainsString('rel="icon"', $this->get('Security/login')->getBody());
    }
}
