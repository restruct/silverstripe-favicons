<?php

declare(strict_types=1);

namespace Restruct\Favicons;

/**
 * One candidate for the project icon: a PNG from a CMS upload, from admintweaks' SiteConfig.FavIcon,
 * or from the YAML `source`. Every variant is generated from the one candidate that is usable.
 *
 * A candidate that was found but is unusable (not a PNG, too small, too large) carries the reason in
 * getError(), so the CMS and dev/build can say why it was skipped instead of silently falling back.
 *
 * The bytes are loaded lazily: head tags and ETags on every page request only need the hash, and
 * reading a stored upload from the asset store just for that would be wasted work.
 */
class IconSource
{
    /** @var callable(): string */
    private $loader;

    private ?string $contents = null;

    /**
     * @param string $hash sha1 of the file contents; keys the generated set and the ETag
     * @param string $origin Human-readable description for hints and logs, eg "upload:favicon/icon.png"
     * @param callable(): string $loader Returns the raw file contents
     * @param string|null $error Why this candidate cannot be used, or null when it can
     */
    public function __construct(
        private string $hash,
        private string $origin,
        callable $loader,
        private ?string $error = null
    ) {
        $this->loader = $loader;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isUsable(): bool
    {
        return $this->error === null;
    }

    public function getContents(): string
    {
        if ($this->contents === null) {
            $this->contents = (string) call_user_func($this->loader);
        }
        return $this->contents;
    }
}
