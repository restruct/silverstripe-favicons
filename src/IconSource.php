<?php

declare(strict_types=1);

namespace Restruct\Favicons;

/**
 * The one project icon every variant is generated from: an SVG or a PNG, from YAML or a CMS upload.
 *
 * The bytes are loaded lazily: building head tags and ETags on every page request only needs the
 * type and hash, and reading a stored upload from the asset store just for that would be wasted work.
 */
class IconSource
{
    public const TYPE_SVG = 'svg';
    public const TYPE_PNG = 'png';

    /** @var callable(): string */
    private $loader;

    private ?string $contents = null;

    /**
     * @param string $type One of the TYPE_ constants
     * @param string $hash Content hash of the source; keys the ETag and the generated-variant cache
     * @param string $origin Human-readable description for hints and logs, eg "upload:favicon/icon.png"
     * @param callable(): string $loader Returns the raw file contents
     */
    public function __construct(
        private string $type,
        private string $hash,
        private string $origin,
        callable $loader
    ) {
        $this->loader = $loader;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function isSvg(): bool
    {
        return $this->type === self::TYPE_SVG;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getContents(): string
    {
        if ($this->contents === null) {
            $this->contents = (string) call_user_func($this->loader);
        }
        return $this->contents;
    }

    /**
     * Cheap content sniff, so a file with the wrong extension (a JPEG renamed to .png, an HTML error
     * page saved as .svg) is rejected instead of producing broken icons.
     */
    public static function looksLike(string $type, string $contents): bool
    {
        if ($type === self::TYPE_PNG) {
            return str_starts_with($contents, "\x89PNG\r\n\x1a\n");
        }
        if ($type === self::TYPE_SVG) {
            # An SVG may start with an XML declaration, a doctype or comments, so look for the root
            # element anywhere in the first few KB rather than at offset 0.
            return stripos(substr($contents, 0, 4096), '<svg') !== false;
        }
        return false;
    }
}
