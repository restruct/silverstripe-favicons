<?php

namespace Restruct\Favicons\Tests\Stub;

use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Dev\TestOnly;
use SilverStripe\View\Requirements;

# The frontend extension is applied to ContentController only when silverstripe/cms is installed;
# without it there is no parent class, and declaring this class would fatal the manifest build.
if (!class_exists(ContentController::class)) {
    return;
}

/**
 * A frontend controller that renders a bare HTML document through the Requirements backend, the way
 * a theme's Page.ss render does, without depending on the host project having a Page template.
 */
class FavTestController extends ContentController implements TestOnly
{
    public function index()
    {
        return Requirements::includeInHTML('<html><head><title>t</title></head><body>frontend</body></html>');
    }
}
