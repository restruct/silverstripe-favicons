<?php

declare(strict_types=1);

namespace Restruct\Favicons\Extensions;

use Restruct\Favicons\FaviconService;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;

/**
 * Optional CMS upload for the icon on Settings, shown only with
 *
 *   Restruct\Favicons\FaviconService:
 *     allow_upload: true
 *
 * (the same opt-in pattern as mfa-bundle's show_mfa_settings and admintweaks'
 * enable_logo_upload_fields). The relation exists regardless, so toggling the option never changes
 * the schema; with allow_upload: false a stored upload is simply ignored.
 *
 * The file is owned by SiteConfig, so saving Settings publishes it (SiteConfigLeftAndMain calls
 * publishRecursive()). Only the published file is ever served; see FaviconService::getLiveFile().
 *
 * Named FaviconSource rather than FavIcon so it cannot collide with admintweaks' own
 * SiteConfig.FavIcon has_one when both modules are installed.
 *
 * @property int $FaviconSourceID
 * @method Image FaviconSource()
 */
class SiteConfigFaviconExtension extends Extension
{
    private static array $has_one = [
        'FaviconSource' => Image::class,
    ];

    private static array $owns = [
        'FaviconSource',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $service = FaviconService::singleton();
        if (!$service->config()->get('allow_upload') || !class_exists(UploadField::class)) {
            return;
        }

        $exts = FaviconService::allowedUploadExtensions();
        $hint = in_array('svg', $exts, true)
            ? _t(__CLASS__ . '.UPLOAD_HINT_SVG', 'SVG or PNG (square, at least 512x512 px). Overrides the icon set in the site configuration.')
            : _t(__CLASS__ . '.UPLOAD_HINT_PNG', 'PNG (square, at least 512x512 px, transparent background). Overrides the icon set in the site configuration.');

        $field = UploadField::create('FaviconSource', _t(__CLASS__ . '.FaviconSource', 'Site icon (favicon)'))
            ->setAllowedExtensions($exts)
            ->setAllowedMaxFileNumber(1)
            ->setFolderName((string) $service->config()->get('upload_folder'))
            ->setDescription($hint . ' ' . $service->getStatusMessage());

        # Next to the title and tagline, which is where a site's identity is set.
        # insertAfter() searches nested tabs; when a project removed Tagline, append to Root.Main.
        if (!$fields->dataFieldByName('Tagline') || !$fields->insertAfter('Tagline', $field, false)) {
            $fields->addFieldToTab('Root.Main', $field);
        }
    }

    /**
     * Silverstripe 5 name of the dev/build hook. Framework 6 renamed it, see onRequireDefaultRecords().
     */
    protected function requireDefaultRecords(): void
    {
        $this->reportStatus();
    }

    /**
     * Silverstripe 6 name of the same hook. Each major fires exactly one of the two names.
     */
    protected function onRequireDefaultRecords(): void
    {
        $this->reportStatus();
    }

    /**
     * One dev/build line saying what is being served, so a missing rasteriser or source is noticed
     * at deploy time rather than in the 404 log.
     */
    protected function reportStatus(): void
    {
        $service = FaviconService::singleton();
        $source = $service->getSource();
        # Red only for a source that is set but cannot be fully served; "nothing configured yet" is a
        # normal state right after installing.
        $degraded = $source && !$service->canRasterise($source);
        DB::alteration_message('Favicons: ' . $service->getStatusMessage(), $degraded ? 'error' : 'notice');
    }
}
