<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Generator\SvgSanitiser;
use SilverStripe\Dev\SapphireTest;

class SvgSanitiserTest extends SapphireTest
{
    protected $usesDatabase = false;

    private const NS = 'xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"';

    public function testLocalAndRemoteReferencesAreRemoved()
    {
        $svg = '<svg ' . self::NS . ' viewBox="0 0 10 10">'
            . '<image xlink:href="/etc/secret.png" width="10" height="10"/>'
            . '<image href="text:/etc/hosts" width="10" height="10"/>'
            . '<image xlink:href="file:///etc/passwd"/>'
            . '<image href="https://example.com/x.png"/>'
            . '<image href="data:image/svg+xml;base64,PHN2Zz48L3N2Zz4="/>'
            . '<image href="data:image/png;base64,iVBORw0KGgo="/>'
            . '<use xlink:href="#shape"/>'
            . '<rect id="shape" width="5" height="5"/>'
            . '</svg>';
        $out = SvgSanitiser::forRasterising($svg);
        $this->assertNotNull($out);
        foreach (['/etc/secret.png', 'text:', 'file:', 'example.com', 'image/svg+xml'] as $gone) {
            $this->assertStringNotContainsString($gone, $out);
        }
        $this->assertStringContainsString('data:image/png;base64', $out, 'embedded bitmaps stay');
        $this->assertStringContainsString('#shape', $out, 'same-document references stay');
        $this->assertStringContainsString('<rect', $out, 'the drawing itself stays');
    }

    public function testCssReferencesAreNeutralised()
    {
        $svg = '<svg ' . self::NS . '>'
            . '<style>@import url(/etc/x.css); rect { fill: url(/etc/p.png); stroke: url(#grad) }</style>'
            . '<rect style="fill:url(\'file:///etc/p.png\')" filter="url(/f.svg#x)" mask="url(#m)"/>'
            . '<circle style="fill:url(/unterminated"/>'
            . '</svg>';
        $out = SvgSanitiser::forRasterising($svg);
        $this->assertNotNull($out);
        $this->assertStringNotContainsString('/etc/', $out);
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('f.svg', $out);
        $this->assertStringNotContainsString('unterminated', $out);
        $this->assertStringContainsString('url(#grad)', $out);
        $this->assertStringContainsString('url(#m)', $out);
    }

    public function testScriptAndForeignObjectAreRemoved()
    {
        $out = SvgSanitiser::forRasterising('<svg ' . self::NS . '><script>alert(1)</script>'
            . '<foreignObject><img xmlns="http://www.w3.org/1999/xhtml" src="/etc/x.png"/></foreignObject>'
            . '<rect width="1" height="1"/></svg>');
        $this->assertStringNotContainsString('script', $out);
        $this->assertStringNotContainsString('foreignObject', $out);
        $this->assertStringNotContainsString('/etc/x.png', $out);
    }

    public function testFailsClosed()
    {
        $this->assertNull(SvgSanitiser::forRasterising(''), 'empty');
        $this->assertNull(SvgSanitiser::forRasterising('<svg><rect></svg>'), 'malformed');
        $this->assertNull(SvgSanitiser::forRasterising('<html><body/></html>'), 'not an svg root');
        $this->assertNull(
            SvgSanitiser::forRasterising('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg ' . self::NS . '><text>&x;</text></svg>'),
            'entity declarations are refused'
        );
        $this->assertNull(SvgSanitiser::forRasterising('<svg ' . self::NS . '>' . str_repeat(' ', SvgSanitiser::MAX_BYTES) . '</svg>'), 'oversized');
        $this->assertNotNull(
            SvgSanitiser::forRasterising('<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg ' . self::NS . '/>'),
            'a plain public DOCTYPE is fine (and not fetched)'
        );
    }
}
