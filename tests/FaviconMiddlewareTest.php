<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Generator\ImageProcessor;
use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;

/**
 * The probe paths through the real request stack (Director middlewares), headers included.
 */
class FaviconMiddlewareTest extends FunctionalTest
{
    # FunctionalTest's session handling and the 404 page path query the database
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(FaviconService::class, 'source', __DIR__ . '/fixtures/icon.png');
    }

    public function testFaviconIcoIsServedWithCacheHeaders()
    {
        $response = $this->get('favicon.ico');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/x-icon', $response->getHeader('Content-Type'));
        $this->assertCount(3, IcoWriter::parse($response->getBody()));

        $etag = $response->getHeader('ETag');
        $this->assertMatchesRegularExpression('/^"[0-9a-f]{16}-[0-9a-f]{8}"$/', $etag);
        $this->assertSame('public, max-age=86400', $response->getHeader('Cache-Control'));
        $this->assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
    }

    public function testVersionedUrlIsImmutable()
    {
        $v = FaviconService::singleton()->getVersion();
        $response = $this->get('favicon.ico?v=' . $v);
        $this->assertSame('public, max-age=31536000, immutable', $response->getHeader('Cache-Control'));

        # A stale ?v= is treated as a bare probe, not cached for a year
        $response = $this->get('favicon.ico?v=0000');
        $this->assertSame('public, max-age=86400', $response->getHeader('Cache-Control'));
    }

    public function testMatchingIfNoneMatchGets304()
    {
        $etag = $this->get('apple-touch-icon.png')->getHeader('ETag');
        $response = $this->get('apple-touch-icon.png', null, ['If-None-Match' => $etag]);
        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame($etag, $response->getHeader('ETag'));

        $response = $this->get('apple-touch-icon.png', null, ['If-None-Match' => 'W/' . $etag . ', "other"']);
        $this->assertSame(304, $response->getStatusCode(), 'weak and listed validators match');

        $response = $this->get('apple-touch-icon.png', null, ['If-None-Match' => '"stale"']);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testEtagChangesWithTheSource()
    {
        $before = $this->get('favicon.ico')->getHeader('ETag');
        Config::modify()->set(FaviconService::class, 'source', __DIR__ . '/fixtures/small.png');
        $after = $this->get('favicon.ico')->getHeader('ETag');
        $this->assertNotSame($before, $after);
    }

    public function testProbesFromThe404LogAreAnswered()
    {
        $probes = [
            'apple-touch-icon.png' => 180,
            'apple-touch-icon-precomposed.png' => 180,
            'apple-touch-icon-120x120.png' => 120,
            'apple-touch-icon-120x120-precomposed.png' => 120,
            'apple-touch-icon-152x152.png' => 152,
            'apple-touch-icon-152x152-precomposed.png' => 152,
            'apple-touch-icon-240x240.png' => 180,
            'apple-touch-icon-240x240-precomposed.png' => 180,
            'icon-192.png' => 192,
            'icon-512.png' => 512,
            'icon-maskable-512.png' => 512,
        ];
        foreach ($probes as $path => $size) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('image/png', $response->getHeader('Content-Type'), $path);
            $this->assertSame([$size, $size], ImageProcessor::dimensions($response->getBody()), $path);
        }

        foreach (['manifest.json', 'site.webmanifest'] as $path) {
            $response = $this->get($path);
            $this->assertSame(200, $response->getStatusCode(), $path);
            $this->assertSame('application/manifest+json', $response->getHeader('Content-Type'));
            $this->assertIsArray(json_decode($response->getBody(), true));
        }
    }

    public function testExtraPathsAreServed()
    {
        $this->assertSame(404, $this->get('sites/favicon.ico')->getStatusCode(), 'not served by default');
        Config::modify()->set(FaviconService::class, 'extra_paths', ['sites/favicon.ico' => 'favicon.ico']);
        $response = $this->get('sites/favicon.ico');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/x-icon', $response->getHeader('Content-Type'));
    }

    public function testNothingIsServedWithoutASource()
    {
        Config::modify()->set(FaviconService::class, 'source', null);
        $this->assertSame(404, $this->get('favicon.ico')->getStatusCode());
        $this->assertSame(404, $this->get('manifest.json')->getStatusCode());
    }

    public function testSvgOnlyServerServesSvgAnd404sTheRest()
    {
        Config::modify()->set(FaviconService::class, 'source', __DIR__ . '/fixtures/icon.svg');
        Config::modify()->set(SvgRasteriser::class, 'rasterisers', []);
        $response = $this->get('icon.svg');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/svg+xml', $response->getHeader('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $response->getHeader('Content-Security-Policy'));
        $this->assertSame(404, $this->get('favicon.ico')->getStatusCode());
        $this->assertSame(404, $this->get('apple-touch-icon.png')->getStatusCode());
        # And a conditional request for a variant that does not exist is not answered with a 304
        $etag = $response->getHeader('ETag');
        $this->assertSame(404, $this->get('favicon.ico', null, ['If-None-Match' => $etag])->getStatusCode());
    }

    public function testOnlyGetAndHeadAreAnswered()
    {
        $this->assertNotSame('image/x-icon', $this->post('favicon.ico', [])->getHeader('Content-Type'));
    }

    public function testOtherUrlsPassThrough()
    {
        $response = $this->get('no-such-page-here');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($response->getHeader('ETag'));
    }
}
