<?php

declare(strict_types=1);

namespace Restruct\Favicons\Control;

use Restruct\Favicons\FaviconService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Injector\Injectable;

/**
 * Answers the root icon probes (/favicon.ico, /apple-touch-icon-152x152-precomposed.png,
 * /manifest.json, ...) before routing.
 *
 * A middleware rather than Director rules because the probe names are a pattern
 * (apple-touch-icon[-NxN][-precomposed].png, any N) plus a configurable alias list, and Director
 * rules match fixed segments only. It also keeps icon requests out of controller init, so they do
 * not start sessions or run page logic.
 *
 * A path the current source cannot produce (eg favicon.ico from an SVG on a server without a
 * rasteriser) is passed on, so it 404s through the normal stack and 404 logging still sees it.
 *
 * A physical file in the webroot (public/favicon.ico) is served by the web server before
 * Silverstripe runs at all, and wins over this middleware.
 */
class FaviconMiddleware implements HTTPMiddleware
{
    use Injectable;

    public function process(HTTPRequest $request, callable $delegate)
    {
        if (!in_array($request->httpMethod(), ['GET', 'HEAD'], true)) {
            return $delegate($request);
        }
        $service = FaviconService::singleton();
        $variant = $service->resolveRequestPath((string) $request->getURL());
        if ($variant === null) {
            return $delegate($request);
        }
        $response = $this->respond($request, $variant);
        return $response ?? $delegate($request);
    }

    /**
     * Build the response for a variant, or null when it cannot be served.
     */
    public function respond(HTTPRequest $request, string $variant): ?HTTPResponse
    {
        $service = FaviconService::singleton();
        $source = $service->getSource();
        $version = $source ? $service->getVersion($source) : null;
        if (!$version) {
            return null;
        }
        $etag = '"' . $version . '-' . substr(md5($variant), 0, 8) . '"';

        # A request carrying the current ?v= came from our own head tags: that URL's content can never
        # change, so it may be cached for a year. A bare probe gets a shorter lifetime plus the ETag.
        $isVersioned = $request->getVar('v') === $version;
        $maxAge = (int) $service->config()->get($isVersioned ? 'versioned_max_age' : 'max_age');
        $cacheControl = 'public, max-age=' . $maxAge . ($isVersioned ? ', immutable' : '');

        # Answer a conditional request before generating anything.
        $ifNoneMatch = (string) $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            # Only answer 304 for a variant that exists; otherwise fall through to a 404.
            if (!array_key_exists($variant, $service->getVariants($source))) {
                return null;
            }
            $response = HTTPResponse::create('', 304);
            $response->addHeader('ETag', $etag);
            $response->addHeader('Cache-Control', $cacheControl);
            return $response;
        }

        $data = $service->getVariant($variant);
        if ($data === null) {
            return null;
        }

        $response = HTTPResponse::create($request->httpMethod() === 'HEAD' ? '' : $data['body'], 200);
        $response->addHeader('Content-Type', $data['type']);
        $response->addHeader('Content-Length', (string) strlen($data['body']));
        $response->addHeader('ETag', $etag);
        $response->addHeader('Cache-Control', $cacheControl);
        $response->addHeader('X-Content-Type-Options', 'nosniff');
        if ($data['type'] === 'image/svg+xml') {
            # An SVG opened directly is a document that could run script; an icon never needs to.
            $response->addHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'");
        }
        return $response;
    }

    protected function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            # Weak validators (W/"...") match too: a proxy may weaken the tag when it recompresses.
            $candidate = preg_replace('#^W/#', '', trim($candidate));
            if ($candidate === $etag) {
                return true;
            }
        }
        return false;
    }
}
