<?php

declare(strict_types=1);

namespace Restruct\Favicons\Extensions;

use Restruct\Favicons\FaviconService;
use SilverStripe\Admin\LeftAndMain;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Extension;
use SilverStripe\Security\Security;
use SilverStripe\View\Requirements;

/**
 * Adds the icon <link> tags to every page a controller renders, without template changes.
 *
 * Applied to ContentController (frontend), LeftAndMain (CMS) and Security (login pages).
 * Requirements::insertHeadTags() is injected before </head> by the Requirements backend whenever a
 * template is rendered, which LeftAndMain.ss, the login-forms theme and any site theme all go
 * through. LeftAndMain.ss emits no icon tags itself on admin 2 (SS5) or admin 3 (SS6), so the CMS
 * otherwise falls back to the browser's /favicon.ico probe; this makes it match the site.
 *
 * onAfterInit is fired by Controller::doInit() on both majors, and was not among the hooks SS6
 * renamed.
 */
class FaviconHeadTagsExtension extends Extension
{
    protected function onAfterInit(): void
    {
        $service = FaviconService::singleton();
        if (!$service->tagsEnabledFor($this->getContext())) {
            return;
        }
        $tags = $service->getHeadTags();
        if ($tags !== '') {
            # The fixed ID makes a second insertion in the same request (Security rendering through
            # a Page controller) overwrite rather than duplicate.
            Requirements::insertHeadTags($tags, FaviconService::HEAD_TAGS_ID);
        }
    }

    /**
     * 'admin', 'security' or 'frontend', matching the *_tags config switches.
     */
    public function getContext(): string
    {
        $owner = $this->getOwner();
        if (class_exists(LeftAndMain::class) && $owner instanceof LeftAndMain) {
            return 'admin';
        }
        if ($owner instanceof Security) {
            return 'security';
        }
        # Without login-forms, Security renders through a Page controller built in
        # Security::getResponseController() around a throwaway page with URLSegment 'Security' and a
        # negative random ID (same marker on SS5 and SS6). That is a Security page, not a frontend one.
        if (class_exists(ContentController::class) && $owner instanceof ContentController) {
            $record = $owner->data();
            if ($record && $record->URLSegment === 'Security' && (int) $record->ID < 0) {
                return 'security';
            }
        }
        return 'frontend';
    }
}
