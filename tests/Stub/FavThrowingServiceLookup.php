<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\FaviconService;
use RuntimeException;
use SilverStripe\Dev\TestOnly;

/**
 * FaviconService whose getHeadTags() itself throws (its own catch bypassed), to prove the
 * $FaviconTags template global does not pass the error on to the page render.
 */
class FavThrowingServiceLookup extends FaviconService implements TestOnly
{
    public function getHeadTags(): string
    {
        throw new RuntimeException('head tags failed');
    }
}
