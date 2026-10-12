<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\PublicFileWriter;
use SilverStripe\Dev\TestOnly;

/**
 * PublicFileWriter with a switchable environment mode, so the live-mode behaviour can be tested on
 * a host (and in CI) that runs in dev, and the dev-mode skip on one that does not. Also records
 * which variants are read from the store, to prove an unchanged sync reads nothing.
 */
class FavEnvWriter extends PublicFileWriter implements TestOnly
{
    public bool $dev = false;

    /** @var string[] Variants read from the store, in order */
    public array $reads = [];

    protected function isDevEnvironment(): bool
    {
        return $this->dev;
    }

    protected function readVariant(string $variant): ?string
    {
        $this->reads[] = $variant;
        return parent::readVariant($variant);
    }
}
