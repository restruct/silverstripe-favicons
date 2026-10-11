<?php

namespace Restruct\Favicons\Tests\Stub;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for restruct/silverstripe-admintweaks' SiteConfigExtension: the same two fields, with
 * the same names and types, so the fallback can be tested without that module installed.
 */
class FavAdmintweaksStub extends Extension implements TestOnly
{
    private static array $db = [
        'ThemeBrowserColor' => 'Varchar(7)',
    ];

    private static array $has_one = [
        'FavIcon' => File::class,
    ];

    private static array $owns = [
        'FavIcon',
    ];
}
