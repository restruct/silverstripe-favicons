<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Makes an SVG safe to hand to a rasteriser, whose output is published to anonymous visitors.
 *
 * The threat is not script (rasterisers do not run it) but references and cost. ImageMagick (with
 * its MSVG or Inkscape delegate) follows `<image xlink:href="/abs/path/secret.png">`, Inkscape's
 * `sodipodi:absref`, `xml:base`, CSS `url()` and more, and draws the referenced local file into the
 * PNG. A denylist kept missing variants (an undeclared xlink prefix, foreign-namespace attributes,
 * CSS escapes), so this is an ALLOWLIST: the document is REBUILT from what is known to be safe, and
 * everything else is left out.
 *
 * Kept:
 * - SVG-namespace elements from ALLOWED_ELEMENTS (shapes, text, structure, gradients, patterns,
 *   clip paths and masks). Not kept: script, foreignObject, animation (set/animate can rewrite an
 *   href), filters (feImage loads files; feTurbulence and friends are where render time explodes),
 *   fonts, and every element in any other namespace.
 * - Attributes in the null namespace only, except names starting with "on" or ending in "ref"
 *   (href, absref, ...). Any prefixed attribute (xlink:*, xml:base, sodipodi:*, inkscape:*) is gone.
 * - One reference attribute per element (href or xlink:href, written back as xlink:href), only
 *   when it is a same-document fragment (`#id`) or, on `<image>` only, an embedded bitmap whose
 *   bytes really are PNG, JPEG, GIF or WebP (an SVG labelled data:image/png is refused: ImageMagick
 *   sniffs content, not labels). An `<image>` without such a bitmap is left out.
 * - CSS (style attributes, style elements, presentation attributes) only when it contains no
 *   backslash (escapes such as `u\72l(` are how url() hides), no `@` rule and no url() other than
 *   `url(#id)`; otherwise that attribute or element is dropped.
 * - Text content, without comments, processing instructions (`<?xml-stylesheet?>`) or CDATA wrappers.
 *
 * Refused (null): any libxml error or warning (an undeclared prefix is a namespace error that
 * loadXML() otherwise shrugs off), a DOCTYPE with an internal subset or entities, a non-`<svg>` root,
 * input over MAX_BYTES, more than MAX_REFERENCES fragment references, reference chains deeper than
 * MAX_REFERENCE_DEPTH (a 1 KB `<use>` bomb renders millions of shapes), reference cycles, and
 * geometry values beyond MAX_COORDINATE.
 */
class SvgSanitiser
{
    public const SVG_NS = 'http://www.w3.org/2000/svg';
    public const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /**
     * Refuse anything larger: an icon is a few KB, and DOM parsing cost grows with input size.
     */
    public const MAX_BYTES = 2097152;

    /** Fragment references (use, pattern/gradient href) allowed in one document. */
    public const MAX_REFERENCES = 50;

    /** Longest allowed chain of fragment references (use -> g with use -> ...). */
    public const MAX_REFERENCE_DEPTH = 3;

    /** Largest absolute value allowed in a geometry attribute such as width or x. */
    public const MAX_COORDINATE = 1000000;

    /** Largest embedded bitmap, decoded. */
    public const MAX_DATA_BYTES = 1048576;

    public const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'title', 'desc', 'switch', 'a',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textPath',
        'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker',
        'image', 'style',
    ];

    protected const GEOMETRY_ATTRIBUTES = ['width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'fx', 'fy'];

    /**
     * The rebuilt SVG, or null when it could not be made safe.
     */
    public static function forRasterising(string $svg): ?string
    {
        $source = self::parse($svg);
        if (!$source) {
            return null;
        }
        $out = new DOMDocument('1.0', 'UTF-8');
        try {
            $root = self::copyElement($source->documentElement, $out);
        } catch (UnsafeSvgException $e) {
            return null;
        }
        if (!$root) {
            return null;
        }
        $out->appendChild($root);
        if (!self::referencesAreBounded($out)) {
            return null;
        }
        $xml = $out->saveXML($out->documentElement);
        return $xml === false ? null : $xml;
    }

    /**
     * Parse, refusing on any libxml error or warning, entity declarations and non-SVG roots. Shared
     * with SvgRasteriser for reading the declared size.
     */
    public static function parse(string $svg): ?DOMDocument
    {
        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            # LIBXML_NONET: never touch the network. No LIBXML_NOENT / LIBXML_DTDLOAD: entities are not
            # substituted and no external DTD is loaded.
            $ok = $doc->loadXML($svg, LIBXML_NONET);
            # loadXML() returns true for namespace errors (eg an undeclared xlink: prefix), so the
            # error list, not the return value, decides.
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$ok || $errors) {
            return null;
        }
        $root = $doc->documentElement;
        if (!$root || $root->namespaceURI !== self::SVG_NS || $root->localName !== 'svg') {
            return null;
        }
        if ($doc->doctype) {
            # A plain <!DOCTYPE svg PUBLIC ...> is harmless (never fetched); an internal subset can
            # declare entities, so it is refused rather than cleaned.
            if ((string) $doc->doctype->internalSubset !== '' || $doc->doctype->entities->length > 0) {
                return null;
            }
        }
        return $doc;
    }

    /**
     * Copy an allowed element and its allowed content into $out. Null drops the element (an image
     * without a usable source, a style element with unsafe CSS); UnsafeSvgException refuses the
     * whole document.
     */
    protected static function copyElement(DOMElement $el, DOMDocument $out): ?DOMElement
    {
        $copy = $out->createElementNS(self::SVG_NS, $el->localName);

        $reference = null;
        foreach ($el->attributes as $attr) {
            $name = $attr->localName;
            $ns = $attr->namespaceURI;
            $lower = strtolower($name);

            # The one reference attribute: plain href (SVG 2) or xlink:href (SVG 1.1), vetted.
            if ($lower === 'href' && ($ns === null || $ns === self::XLINK_NS)) {
                $reference = $attr->value;
                continue;
            }
            # Everything else must be an unprefixed, non-event, non-reference attribute.
            if ($ns !== null || str_starts_with($lower, 'on') || str_ends_with($lower, 'ref')) {
                continue;
            }
            $value = $attr->value;
            if (in_array($name, self::GEOMETRY_ATTRIBUTES, true) && !self::geometryIsSane($value)) {
                throw new UnsafeSvgException("{$name}={$value} is out of range");
            }
            if (stripos($value, 'url(') !== false || str_contains($value, '\\') || str_contains($value, '@')) {
                $value = self::cleanCss($value);
                if ($value === null) {
                    continue;
                }
            }
            $copy->setAttribute($name, $value);
        }

        $href = $reference === null ? null : self::vetReference($reference);
        if ($el->localName === 'image' && ($href === null || str_starts_with($href, '#'))) {
            # An image draws only an embedded bitmap; without one it draws nothing, so leave it out.
            return null;
        }
        if ($href !== null && $el->localName !== 'image' && !str_starts_with($href, '#')) {
            # Embedded data is for <image> only; use/pattern/gradients reference fragments.
            $href = null;
        }
        if ($href !== null) {
            # Written as xlink:href, which every renderer understands (plain SVG 2 href is not read
            # by older ImageMagick MSVG builds).
            $copy->setAttributeNS(self::XLINK_NS, 'xlink:href', $href);
        }

        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement) {
                if ($child->namespaceURI !== self::SVG_NS || !in_array($child->localName, self::ALLOWED_ELEMENTS, true)) {
                    continue;
                }
                $childCopy = self::copyElement($child, $out);
                if ($childCopy) {
                    $copy->appendChild($childCopy);
                }
            } elseif ($child instanceof DOMText) {
                # DOMText covers CDATA too; comments and processing instructions are not copied.
                $text = $child->data;
                if ($el->localName === 'style') {
                    $text = self::cleanCss($text);
                    if ($text === null) {
                        return null;
                    }
                }
                $copy->appendChild($out->createTextNode($text));
            }
        }
        return $copy;
    }

    /**
     * The reference to keep, normalised, or null to drop it.
     */
    public static function vetReference(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', $value)) {
            return $value;
        }
        if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,([A-Za-z0-9+/=\s]+)$#i', $value, $m)) {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_DATA_BYTES) {
            return null;
        }
        # The label must match the content: renderers sniff bytes, so "png" carrying SVG is an SVG.
        $magic = [
            'png' => "\x89PNG\r\n\x1a\n",
            'jpg' => "\xff\xd8\xff",
            'jpeg' => "\xff\xd8\xff",
            'gif' => 'GIF8',
        ];
        $type = strtolower($m[1]);
        if ($type === 'webp') {
            $ok = str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP';
        } else {
            $ok = str_starts_with($bytes, $magic[$type]);
        }
        return $ok ? 'data:image/' . $type . ';base64,' . base64_encode($bytes) : null;
    }

    /**
     * CSS that may stay, or null when it must be dropped: no backslash escapes, no at-rules, and no
     * url() other than a same-document fragment.
     */
    public static function cleanCss(string $css): ?string
    {
        if (str_contains($css, '\\') || str_contains($css, '@')) {
            return null;
        }
        if (preg_match_all('/url\s*\(\s*([\'"]?)(.*?)\1\s*\)/is', $css, $all, PREG_SET_ORDER)) {
            foreach ($all as $m) {
                if (!preg_match('/^#[A-Za-z_][A-Za-z0-9_.:-]*$/', trim($m[2]))) {
                    return null;
                }
            }
        }
        # An unterminated or odd url( the pattern above could not pair up.
        $withoutGood = (string) preg_replace('/url\s*\(\s*([\'"]?)#[A-Za-z_][A-Za-z0-9_.:-]*\1\s*\)/i', '', $css);
        if (stripos($withoutGood, 'url') !== false && preg_match('/url\s*\(/i', $withoutGood)) {
            return null;
        }
        return $css;
    }

    protected static function geometryIsSane(string $value): bool
    {
        if (!preg_match('/^\s*([+-]?(?:[0-9]*\.?[0-9]+)(?:e[+-]?[0-9]+)?)/i', $value, $m)) {
            # Keywords and percentages ("auto", "100%") are fine.
            return true;
        }
        $n = (float) $m[1];
        return is_finite($n) && abs($n) <= self::MAX_COORDINATE;
    }

    /**
     * Count fragment references and measure the longest chain, refusing bombs and cycles.
     */
    protected static function referencesAreBounded(DOMDocument $doc): bool
    {
        $byId = [];
        $refs = 0;
        foreach ($doc->getElementsByTagName('*') as $el) {
            /** @var DOMElement $el */
            if ($el->hasAttribute('id')) {
                $byId[$el->getAttribute('id')] = $el;
            }
            if (str_starts_with($el->getAttributeNS(self::XLINK_NS, 'href'), '#')) {
                $refs++;
            }
        }
        if ($refs > self::MAX_REFERENCES) {
            return false;
        }
        $memo = [];
        foreach ($doc->getElementsByTagName('*') as $el) {
            if (self::depth($el, $byId, $memo, []) > self::MAX_REFERENCE_DEPTH) {
                return false;
            }
        }
        return true;
    }

    /**
     * Longest reference chain starting at or below $node. A cycle counts as infinitely deep.
     *
     * @param array<string, DOMElement> $byId
     * @param array<string, int> $memo
     * @param array<string, bool> $visiting
     */
    protected static function depth(DOMNode $node, array $byId, array &$memo, array $visiting): int
    {
        $max = 0;
        if ($node instanceof DOMElement) {
            $href = $node->getAttributeNS(self::XLINK_NS, 'href');
            if (str_starts_with($href, '#')) {
                $id = substr($href, 1);
                if (isset($visiting[$id])) {
                    return PHP_INT_MAX;
                }
                if (!isset($memo[$id])) {
                    $memo[$id] = isset($byId[$id])
                        ? self::depth($byId[$id], $byId, $memo, $visiting + [$id => true])
                        : 0;
                }
                $max = $memo[$id] === PHP_INT_MAX ? PHP_INT_MAX : 1 + $memo[$id];
            }
        }
        foreach ($node->childNodes as $child) {
            if ($max === PHP_INT_MAX) {
                break;
            }
            $max = max($max, self::depth($child, $byId, $memo, $visiting));
        }
        return $max;
    }
}
