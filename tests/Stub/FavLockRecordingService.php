<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\IconSource;
use SilverStripe\Dev\TestOnly;

/**
 * FaviconService that records, from inside buildSet(), whether the set's build lock is held.
 */
class FavLockRecordingService extends FaviconService implements TestOnly
{
    /** @var bool[] */
    public array $lockHeldDuringBuild = [];

    protected function buildSet(IconSource $source, string $setId, ?array $previous): ?array
    {
        $this->lockHeldDuringBuild[] = is_file($this->buildLockPath($setId));
        return parent::buildSet($source, $setId, $previous);
    }
}
