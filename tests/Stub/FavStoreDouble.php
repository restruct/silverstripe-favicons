<?php

namespace Restruct\Favicons\Tests\Stub;

use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\TestOnly;

/**
 * The active TestAssetStore with switchable faults, standing in for a store whose delete() throws
 * (permissions, a remote error) or whose files disappear while a set is being drawn.
 *
 * Parameters are untyped like the parent's on both majors; the bool return type is allowed against
 * Silverstripe 5's untyped parent.
 */
class FavStoreDouble extends TestAssetStore implements TestOnly
{
    public static bool $throwOnDelete = false;

    /** Filenames reported as missing (exists() false, no contents) */
    public static array $vanished = [];

    /**
     * Swap the active test store for a double on the same filesystems.
     */
    public static function install(): void
    {
        /** @var TestAssetStore $active */
        $active = Injector::inst()->get(AssetStore::class);
        $double = new static();
        $double->setPublicFilesystem($active->getPublicFilesystem());
        $double->setProtectedFilesystem($active->getProtectedFilesystem());
        $double->setPublicResolutionStrategy($active->getPublicResolutionStrategy());
        $double->setProtectedResolutionStrategy($active->getProtectedResolutionStrategy());
        Injector::inst()->registerService($double, AssetStore::class);
        static::$throwOnDelete = false;
        static::$vanished = [];
    }

    public function delete($filename, $hash): bool
    {
        if (static::$throwOnDelete) {
            throw new \RuntimeException('Unable to delete file at location: ' . $filename);
        }
        return parent::delete($filename, $hash);
    }

    public function exists($filename, $hash, $variant = null): bool
    {
        return !in_array($filename, static::$vanished, true) && parent::exists($filename, $hash, $variant);
    }

    public function getAsString($filename, $hash, $variant = null): ?string
    {
        return in_array($filename, static::$vanished, true) ? null : parent::getAsString($filename, $hash, $variant);
    }
}
