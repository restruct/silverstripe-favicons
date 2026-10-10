<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Generator\ImageProcessor;
use Restruct\Favicons\Generator\SvgRasteriser;
use Restruct\Favicons\Generator\SvgSanitiser;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Every known way to make a rasteriser draw a local file, from two review rounds, as fixtures in
 * tests/fixtures/bypass/. Each draws NOTHING unless the attack works (shapes are fill="none", the
 * only paint comes from the referenced "secret" file or stylesheet). So:
 *
 * - the sanitised SVG must not mention the secret, the external SVG or the stylesheet at all;
 * - files named REFUSED_* must be refused outright;
 * - rendered by each rasteriser available on this machine, the result must be fully transparent.
 *
 * The placeholders are filled in at run time, so the fixtures point at real files on any machine.
 */
class SvgBypassTest extends SapphireTest
{
    protected $usesDatabase = false;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        # Scratch files the attacks point at: the secret (an opaque PNG), an external SVG that draws
        # it, and a stylesheet that would paint everything red.
        $this->dir = sys_get_temp_dir() . '/favbypass-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        copy(__DIR__ . '/fixtures/icon.png', $this->dir . '/icon.png');
        file_put_contents(
            $this->dir . '/ext.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><g id="q">'
            . '<image width="64" height="64" xlink:href="' . $this->dir . '/icon.png"/><rect width="64" height="64" fill="red"/></g></svg>'
        );
        file_put_contents($this->dir . '/x.css', 'rect, .r { fill: red !important; }');
        file_put_contents($this->dir . '/ext.dtd', '<!ENTITY red "red">');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function bypassFiles(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/fixtures/bypass/*.svg') as $file) {
            $out[basename($file, '.svg')] = [basename($file)];
        }
        return $out;
    }

    private function load(string $name): string
    {
        $secret = $this->dir . '/icon.png';
        $inner = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="64" height="64">'
            . '<image width="64" height="64" xlink:href="' . $secret . '"/></svg>';
        return strtr((string) file_get_contents(__DIR__ . '/fixtures/bypass/' . $name), [
            '__SECRET__' => $secret,
            '__SECRET_NOSLASH__' => ltrim($secret, '/'),
            '__EXT__' => $this->dir . '/ext.svg',
            '__CSS__' => $this->dir . '/x.css',
            '__DIRNAME__' => $this->dir,
            '__INNER_B64__' => base64_encode($inner),
        ]);
    }

    /**
     * @dataProvider bypassFiles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bypassFiles')]
    public function testSanitiserRemovesOrRefuses(string $name)
    {
        $clean = SvgSanitiser::forRasterising($this->load($name));
        if (str_starts_with($name, 'REFUSED_')) {
            $this->assertNull($clean, "$name must be refused");
            return;
        }
        $this->assertNotNull($clean, "$name should be cleaned, not refused");
        $this->assertStringNotContainsString($this->dir, $clean, "$name still references a local file");
        $this->assertStringNotContainsString('base64', $clean, "$name kept an embedded non-bitmap");
        foreach (['script', 'foreignObject', 'feImage', 'xml:base', 'absref', 'include', 'stylesheet', '@', '\\'] as $gone) {
            $this->assertStringNotContainsString($gone, $clean, "$name kept $gone");
        }
    }

    /**
     * @dataProvider bypassFiles
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bypassFiles')]
    public function testRenderedOutputDrawsNothing(string $name)
    {
        if (!ImageProcessor::hasGd()) {
            $this->markTestSkipped('pixel check reads with GD');
        }
        $clean = SvgSanitiser::forRasterising($this->load($name));
        if ($clean === null) {
            $this->assertStringStartsWith('REFUSED_', $name);
            return;
        }
        $rendered = 0;
        foreach (['rsvg-convert', 'imagick'] as $rasteriser) {
            Config::modify()->set(SvgRasteriser::class, 'rasterisers', [$rasteriser]);
            $r = SvgRasteriser::create();
            if ($r->getAvailable() !== $rasteriser) {
                continue;
            }
            $png = $r->rasterise($clean, 64);
            if ($png === null) {
                # Refusing to render is as safe as rendering nothing
                continue;
            }
            $rendered++;
            $im = imagecreatefromstring($png);
            $opaque = 0;
            for ($x = 0; $x < imagesx($im); $x++) {
                for ($y = 0; $y < imagesy($im); $y++) {
                    if (((imagecolorat($im, $x, $y) >> 24) & 0x7f) < 127) {
                        $opaque++;
                    }
                }
            }
            $this->assertSame(0, $opaque, "$name drew something with $rasteriser");
        }
        if (!$rendered) {
            $this->markTestSkipped('No SVG rasteriser rendered on this machine');
        }
    }

    private function useBomb(int $levels, int $fanOut): string
    {
        $defs = '<rect id="l0" width="1" height="1" fill="red"/>';
        for ($l = 1; $l <= $levels; $l++) {
            $defs .= '<g id="l' . $l . '">' . str_repeat('<use xlink:href="#l' . ($l - 1) . '"/>', $fanOut) . '</g>';
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="64" height="64">'
            . '<defs>' . $defs . '</defs><use xlink:href="#l' . $levels . '"/></svg>';
    }

    public function testUseBombsAreRefused()
    {
        # The classic 1 KB bomb: 9 levels of 10-fold fan-out, a billion rectangles
        $this->assertNull(SvgSanitiser::forRasterising($this->useBomb(9, 10)));
        # Depth alone: four levels of single references
        $this->assertNull(SvgSanitiser::forRasterising($this->useBomb(4, 1)), 'deeper than MAX_REFERENCE_DEPTH');
        # Count alone: 51 flat references
        $this->assertNull(SvgSanitiser::forRasterising($this->useBomb(1, 51)), 'more than MAX_REFERENCES');
        # Within limits: an icon that reuses a shape a few times
        $this->assertNotNull(SvgSanitiser::forRasterising($this->useBomb(2, 5)));
    }

    public function testReferenceCyclesAreRefused()
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink">'
            . '<g id="a"><use xlink:href="#b"/></g><g id="b"><use xlink:href="#a"/></g></svg>';
        $this->assertNull(SvgSanitiser::forRasterising($svg));
    }
}
