<?php

declare(strict_types=1);

namespace Restruct\Favicons\Control;

use Restruct\Favicons\FaviconService;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
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
 * The files are streamed from the AssetStore, where FaviconService keeps the generated set, rather
 * than redirected to: a redirect would cost every probe an extra round trip, and some clients do not
 * follow redirects for icons at all.
 *
 * A path the current source cannot produce (no usable source, or a PNG the image backend cannot
 * decode) is passed on, so it 404s through the normal stack and 404 logging still sees it.
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

        # Answer a conditional request without reading the file.
        $ifNoneMatch = (string) $request->getHeader('If-None-Match');
        if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
            # Only answer 304 for a variant that can be served; otherwise fall through to a 404.
            if (!$service->canServe($variant)) {
                return null;
            }
            $response = HTTPResponse::create('', 304);
            $response->addHeader('ETag', $etag);
            $response->addHeader('Cache-Control', $cacheControl);
            return $response;
        }

        $data = $service->openVariant($variant);
        if ($data === null) {
            return null;
        }

        if (isset($data['stream'])) {
            $stream = $data['stream'];
            # Known for local and most remote adapters; omitted (chunked) when the stream cannot say.
            $stat = @fstat($stream);
            $length = is_array($stat) && !empty($stat['size']) ? (int) $stat['size'] : null;
            if ($request->httpMethod() === 'HEAD') {
                fclose($stream);
                $response = HTTPResponse::create('', 200);
                if ($length) {
                    $response->addHeader('Content-Length', (string) $length);
                }
            } else {
                $response = HTTPStreamResponse::create($stream, $length, 200);
            }
        } else {
            $body = (string) $data['body'];
            $response = HTTPResponse::create($request->httpMethod() === 'HEAD' ? '' : $body, 200);
            $response->addHeader('Content-Length', (string) strlen($body));
        }
        $response->addHeader('Content-Type', $data['type']);
        $response->addHeader('ETag', $etag);
        $response->addHeader('Cache-Control', $cacheControl);
        $response->addHeader('X-Content-Type-Options', 'nosniff');
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
