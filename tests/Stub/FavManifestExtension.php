<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\IconSource;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Uses FaviconService's updateManifestData hook the way a project would: adds a member, changes
 * one, and removes one by setting it to null. Off unless a test switches it on, so it does not
 * change the manifest for the other tests in the run.
 */
class FavManifestExtension extends Extension implements TestOnly
{
    public static bool $active = false;

    /**
     * Sources seen by the hook, so a test can check the source is passed in.
     *
     * @var array<int, IconSource|null>
     */
    public static array $sources = [];

    protected function updateManifestData(array &$fields, ?IconSource $source): void
    {
        if (!static::$active) {
            return;
        }
        static::$sources[] = $source;
        $fields['id'] = '/?pwa';
        $fields['display'] = 'standalone';
        $fields['background_color'] = null;
    }
}
