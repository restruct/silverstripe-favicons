<?php

declare(strict_types=1);

namespace Restruct\Favicons\Forms;

use Restruct\Favicons\FaviconService;
use SilverStripe\Assets\Upload_Validator;

/**
 * Upload validator for the favicon field: runs the field's normal validator first, then refuses
 * anything that is not a square PNG of at least FaviconService::MIN_SOURCE_SIZE px, so the editor
 * gets the reason at upload time instead of an icon that silently is not used.
 *
 * Wraps the validator the field already had rather than replacing it, because that one may be
 * silverstripe/mimevalidator's MimeUploadValidator (swapped in through the Injector) or a project's
 * own, and its checks (size limit, extension, MIME type) must keep applying. Upload only calls
 * setTmpFile(), validate() and getErrors(); the field also reads and writes the allowed extensions
 * and size limit through its validator, so those are forwarded too.
 */
class IconUploadValidator extends Upload_Validator
{
    public function __construct(protected Upload_Validator $inner)
    {
    }

    public function getInner(): Upload_Validator
    {
        return $this->inner;
    }

    public function setTmpFile($tmpFile)
    {
        parent::setTmpFile($tmpFile);
        $this->inner->setTmpFile($tmpFile);
        return $this;
    }

    public function validate()
    {
        $this->errors = [];
        if (!$this->inner->validate()) {
            return false;
        }
        # The inner validator returns true for "no file"; nothing to check then either.
        $path = (string) ($this->tmpFile['tmp_name'] ?? '');
        if (empty($this->tmpFile['name']) || $path === '' || !is_file($path)) {
            return true;
        }
        $error = FaviconService::singleton()->checkPng((string) file_get_contents($path, false, null, 0, 64));
        if ($error !== null) {
            $this->errors[] = $error;
            return false;
        }
        return true;
    }

    public function getErrors()
    {
        return array_merge($this->inner->getErrors(), $this->errors);
    }

    public function clearErrors()
    {
        $this->errors = [];
        $this->inner->clearErrors();
        return $this;
    }

    public function getAllowedExtensions()
    {
        return $this->inner->getAllowedExtensions();
    }

    public function setAllowedExtensions($rules)
    {
        $this->inner->setAllowedExtensions($rules);
        # Keep the public property in step for code that reads it directly.
        $this->allowedExtensions = $this->inner->allowedExtensions;
        return $this;
    }

    public function getAllowedMaxFileSize($ext = null)
    {
        return $this->inner->getAllowedMaxFileSize($ext);
    }

    public function getLargestAllowedMaxFileSize()
    {
        return $this->inner->getLargestAllowedMaxFileSize();
    }

    public function setAllowedMaxFileSize($rules)
    {
        $this->inner->setAllowedMaxFileSize($rules);
        return $this;
    }
}
