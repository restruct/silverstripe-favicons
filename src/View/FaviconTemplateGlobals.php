<?php

declare(strict_types=1);

namespace Restruct\Favicons\View;

use Psr\Log\LoggerInterface;
use Restruct\Favicons\FaviconService;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\View\TemplateGlobalProvider;
use Throwable;

/**
 * `$FaviconTags` in any template: the same tags FaviconHeadTagsExtension inserts, for projects that
 * set frontend_tags: false (or admin_tags / security_tags) and place the tags in their own <head>.
 *
 * Not tied to the *_tags switches: a template that asks for the tags gets them. It does not insert
 * through Requirements either, so with frontend_tags still on a page would carry the tags twice;
 * the README says to switch the automatic insertion off when using this.
 */
class FaviconTemplateGlobals implements TemplateGlobalProvider
{
    public static function get_template_global_variables()
    {
        return [
            'FaviconTags' => 'getFaviconTags',
        ];
    }

    /**
     * The head tags as an HTML fragment (so the template does not escape them), or an empty one.
     * Never throws: a page must not fail to render because of an icon. FaviconService::getHeadTags()
     * catches its own errors; this catch also covers resolving the service and building the field.
     */
    public static function getFaviconTags(): DBHTMLText
    {
        $tags = '';
        try {
            $tags = FaviconService::singleton()->getHeadTags();
        } catch (Throwable $e) {
            try {
                Injector::inst()->get(LoggerInterface::class)->warning('Favicon tags left out: ' . $e->getMessage());
            } catch (Throwable $ignored) {
            }
        }
        /** @var DBHTMLText $field */
        $field = DBField::create_field('HTMLFragment', $tags);
        return $field;
    }
}
