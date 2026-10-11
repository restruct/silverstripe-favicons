<?php

namespace Restruct\Favicons\Tests\Stub;

use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Dev\TestOnly;

/**
 * The framework's own image backend, counting the images handed to it, so a test can prove the
 * variants were drawn through whatever Image_Backend the Injector provides.
 *
 * Only setImageResource() is overridden because its parameter is untyped on both majors; adding
 * the `static` return type is allowed against Silverstripe 5's untyped parent and matches 6.
 */
class FavSpyBackend extends InterventionBackend implements TestOnly
{
    /**
     * Width of every image handed to a backend: the source as loaded from the store, and each
     * drawn variant.
     *
     * @var int[]
     */
    public static array $widths = [];

    public function setImageResource($image): static
    {
        if ($image !== null) {
            # width() on Intervention 3, getWidth() on 2
            static::$widths[] = method_exists($image, 'width') ? (int) $image->width() : (int) $image->getWidth();
        }
        return parent::setImageResource($image);
    }
}
