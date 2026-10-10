<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Makes an SVG safe to hand to a rasteriser, whose output is published to anonymous visitors.
 *
 * The threat is not script (rasterisers do not run it) but references: ImageMagick (with its MSVG
 * or Inkscape delegate) follows `<image xlink:href="/abs/path/secret.png">` and draws that local file
 * into the PNG. svg-images' upload sanitiser (enshrined/svg-sanitize, removeRemoteReferences) keeps
 * absolute local paths, so an uploaded SVG is not enough on its own, and a YAML source may come from
 * a theme package nobody reviewed.
 *
 * So, before any rasteriser sees it:
 * - every href / xlink:href that is not a same-document fragment (`#id`) or an embedded bitmap
 *   (`data:image/png|jpeg|gif|webp`) is removed;
 * - every CSS `url(...)` that is not `url(#id)`, in style attributes, presentation attributes and
 *   `<style>` elements, becomes `none`, and `@import` rules are dropped;
 * - `<script>` and `<foreignObject>` are removed (foreignObject can carry HTML with its own src);
 * - a DOCTYPE with an internal subset (entity declarations) is refused outright.
 *
 * Parsed with DOMDocument and LIBXML_NONET, without LIBXML_NOENT/DTDLOAD, so no external entity or
 * DTD is ever fetched. Anything that does not parse as an `<svg>` document fails closed (null).
 */
class SvgSanitiser
{
    /**
     * Refuse anything larger: an icon is a few KB, and DOM parsing cost grows with input size.
     */
    public const MAX_BYTES = 2097152;

    /**
     * The cleaned SVG, or null when it could not be parsed safely.
     */
    public static function forRasterising(string $svg): ?string
    {
        $doc = self::parse($svg);
        if (!$doc) {
            return null;
        }

        $xpath = new DOMXPath($doc);

        # Elements that can only add risk to a rendered icon.
        foreach (['script', 'foreignObject'] as $name) {
            foreach (iterator_to_array($xpath->query('//*[local-name()="' . $name . '"]')) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*')) as $el) {
            /** @var DOMElement $el */
            foreach (iterator_to_array($el->attributes) as $attr) {
                /** @var DOMAttr $attr */
                if (strtolower($attr->localName) === 'href') {
                    if (!self::isSafeHref($attr->value)) {
                        $el->removeAttributeNode($attr);
                    }
                    continue;
                }
                if (stripos($attr->value, 'url(') !== false || stripos($attr->value, '@import') !== false) {
                    $attr->value = self::cleanCss($attr->value);
                }
            }
            if (strtolower($el->localName) === 'style') {
                $el->textContent = self::cleanCss($el->textContent);
            }
        }

        $out = $doc->saveXML($doc->documentElement);
        return $out === false ? null : $out;
    }

    /**
     * Parse, refusing entity declarations and non-SVG roots. Shared with SvgRasteriser for reading the
     * declared size.
     */
    public static function parse(string $svg): ?DOMDocument
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            # LIBXML_NONET: never touch the network. No LIBXML_NOENT / LIBXML_DTDLOAD: entities are not
            # substituted and no external DTD is loaded.
            $ok = $doc->loadXML($svg, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok || !$doc->documentElement || strtolower($doc->documentElement->localName) !== 'svg') {
            return null;
        }
        if ($doc->doctype) {
            # A plain <!DOCTYPE svg PUBLIC ...> is harmless; an internal subset can declare entities
            # (billion laughs, external SYSTEM entities), so it is refused rather than cleaned.
            if ((string) $doc->doctype->internalSubset !== '' || $doc->doctype->entities->length > 0) {
                return null;
            }
        }
        return $doc;
    }

    public static function isSafeHref(string $value): bool
    {
        $value = trim($value);
        if (str_starts_with($value, '#')) {
            return true;
        }
        # Embedded bitmaps only; an embedded SVG could carry its own external references.
        return (bool) preg_match('#^data:image/(png|jpe?g|gif|webp)[;,]#i', $value);
    }

    public static function cleanCss(string $css): string
    {
        $css = (string) preg_replace('/@import[^;]*;?/i', '', $css);
        $css = (string) preg_replace_callback(
            '/url\(\s*([\'"]?)(.*?)\1\s*\)/is',
            fn($m) => str_starts_with(trim($m[2]), '#') ? $m[0] : 'none',
            $css
        );
        # Fail closed on anything the pattern above could not pair up (an unterminated url(, odd
        # escaping): if a non-fragment url( survives, drop the whole value.
        if (preg_match('/url\(\s*[\'"]?\s*(?!#)/i', $css)) {
            return '';
        }
        return $css;
    }
}
