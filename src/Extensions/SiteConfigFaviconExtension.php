<?php

declare(strict_types=1);

namespace Restruct\Favicons\Extensions;

use Restruct\Favicons\FaviconService;
use Restruct\Favicons\Forms\IconUploadValidator;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\File;
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

        $hint = _t(
            __CLASS__ . '.UPLOAD_HINT',
            'PNG, square, at least {min}x{min} px, preferably with a transparent background. Overrides the icon set in the site configuration.',
            ['min' => FaviconService::MIN_SOURCE_SIZE]
        );

        $field = UploadField::create('FaviconSource', _t(__CLASS__ . '.FaviconSource', 'Site icon (favicon)'))
            ->setAllowedExtensions(FaviconService::allowedUploadExtensions())
            ->setAllowedMaxFileNumber(1)
            ->setFolderName((string) $service->config()->get('upload_folder'))
            # Picking an existing file from the library skips upload validation, so only uploading
            # is offered; validateFaviconSource() below still checks whatever ends up saved.
            ->setAttachEnabled(false)
            # The description is rendered as HTML, and the status names the uploaded file.
            ->setDescription(htmlspecialchars($hint . ' ' . $service->getStatusMessage(), ENT_QUOTES));
        # Square PNG of at least 512 px, checked at upload time on top of the field's own checks.
        $upload = $field->getUpload();
        if (!$upload->getValidator() instanceof IconUploadValidator) {
            $upload->setValidator(new IconUploadValidator($upload->getValidator()));
        }

        # Next to the title and tagline, which is where a site's identity is set.
        # insertAfter() searches nested tabs; when a project removed Tagline, append to Root.Main.
        if (!$fields->dataFieldByName('Tagline') || !$fields->insertAfter('Tagline', $field, false)) {
            $fields->addFieldToTab('Root.Main', $field);
        }
    }

    /**
     * Silverstripe 5 name of the validation hook. Framework 6 renamed it, see updateValidate().
     *
     * @param \SilverStripe\ORM\ValidationResult $result
     */
    protected function validate($result): void
    {
        $this->validateFaviconSource($result);
    }

    /**
     * Silverstripe 6 name of the same hook.
     *
     * @param \SilverStripe\Core\Validation\ValidationResult $result
     */
    protected function updateValidate($result): void
    {
        $this->validateFaviconSource($result);
    }

    /**
     * Refuse saving a newly chosen icon that cannot be used. Backs up the upload validator for files
     * that reach the relation another way (an API, a migration, a form without that validator).
     * Only a changed relation is checked, so an older unusable file never blocks unrelated edits.
     *
     * @param object $result ValidationResult (namespace differs between Silverstripe 5 and 6)
     */
    protected function validateFaviconSource($result): void
    {
        $owner = $this->getOwner();
        $id = (int) $owner->FaviconSourceID;
        if (!$id || !$owner->isChanged('FaviconSourceID') || !FaviconService::config()->get('allow_upload')) {
            return;
        }
        $file = File::get()->byID($id);
        $stream = $file ? $file->getStream() : null;
        $head = is_resource($stream) ? (string) fread($stream, 64) : '';
        if (is_resource($stream)) {
            fclose($stream);
        }
        $error = FaviconService::singleton()->checkPng($head);
        if ($error !== null) {
            $result->addFieldError('FaviconSource', $error);
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
     * One dev/build line saying what is being served, so an unusable source is noticed at deploy
     * time rather than in the 404 log. getStatusMessage() also generates the set when it does not
     * exist yet, so the first visitor after a deploy does not pay for it.
     */
    protected function reportStatus(): void
    {
        $service = FaviconService::singleton();
        $message = $service->getStatusMessage();
        # Red only for a source that is set but not (fully) used; "nothing configured yet" is a
        # normal state right after installing.
        DB::alteration_message('Favicons: ' . $message, $service->isDegraded() ? 'error' : 'notice');
    }
}
