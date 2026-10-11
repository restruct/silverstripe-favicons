<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\FaviconService;
use RuntimeException;
use SilverStripe\Dev\TestOnly;

/**
 * FaviconService whose tag building fails, to prove a page render survives it.
 */
class FavThrowingTagsService extends FaviconService implements TestOnly
{
    protected function buildHeadTags(): string
    {
        throw new RuntimeException('tag building failed');
    }
}
