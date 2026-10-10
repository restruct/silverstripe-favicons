<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\Generator\SvgRasteriser;
use RuntimeException;
use SilverStripe\Dev\TestOnly;

/**
 * Real Imagick limits (applyImagickLimits/restoreImagickLimits are inherited), but a render that
 * throws half way, to prove the process-wide limits are restored on the failure path too.
 */
class FavThrowingImagickRasteriser extends SvgRasteriser implements TestOnly
{
    protected function createImagick(): object
    {
        return new class {
            public function __call(string $name, array $args)
            {
                if ($name === 'readImageBlob') {
                    throw new RuntimeException('simulated ImageMagick failure');
                }
                return true;
            }
        };
    }
}
