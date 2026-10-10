<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Generator\ImageProcessor;
use SilverStripe\Dev\SapphireTest;

class ImageProcessorTest extends SapphireTest
{
    protected $usesDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!ImageProcessor::hasGd()) {
            $this->markTestSkipped('The pixel checks read the output with GD');
        }
    }

    /**
     * @return array{0:int, 1:int, 2:int, 3:int} r, g, b, alpha (0 opaque - 127 transparent)
     */
    private function pixel(string $png, int $x, int $y): array
    {
        $im = imagecreatefromstring($png);
        $c = imagecolorsforindex($im, imagecolorat($im, $x, $y));
        return [$c['red'], $c['green'], $c['blue'], $c['alpha']];
    }

    public function testTransparentSquareKeepsTransparency()
    {
        $out = ImageProcessor::create()->square(file_get_contents(__DIR__ . '/fixtures/icon.png'), 192);
        $this->assertSame([192, 192], ImageProcessor::dimensions($out));
        $this->assertSame(127, $this->pixel($out, 0, 0)[3], 'corner stays fully transparent');
        $this->assertSame(0, $this->pixel($out, 96, 96)[3], 'centre is the opaque icon');
    }

    public function testBackgroundFlattensTransparency()
    {
        $out = ImageProcessor::create()->square(file_get_contents(__DIR__ . '/fixtures/icon.png'), 180, '#ff0000');
        $this->assertSame([180, 180], ImageProcessor::dimensions($out));
        $this->assertSame([255, 0, 0, 0], $this->pixel($out, 0, 0), 'corner is the opaque background');
    }

    public function testShortHexAndInvalidColours()
    {
        $this->assertSame([255, 255, 255], ImageProcessor::hexToRgb('#fff'));
        $this->assertSame([0x12, 0x34, 0x56], ImageProcessor::hexToRgb('123456'));
        $this->assertSame([255, 255, 255], ImageProcessor::hexToRgb('not-a-colour'));
    }

    public function testPaddingShrinksTheIcon()
    {
        $src = file_get_contents(__DIR__ . '/fixtures/icon.png');
        # The fixture is a filled circle 2px inside its 512 edge. With 20% padding on a 100px canvas
        # the circle spans ~60px, so x=15 on the centre line is background, not icon.
        $padded = ImageProcessor::create()->square($src, 100, '#ffffff', 20);
        $this->assertSame([255, 255, 255, 0], $this->pixel($padded, 15, 50));
        $unpadded = ImageProcessor::create()->square($src, 100, '#ffffff', 0);
        $this->assertNotSame([255, 255, 255, 0], $this->pixel($unpadded, 15, 50));
    }

    public function testNonSquareSourceIsLetterboxed()
    {
        $this->assertSame([0, 25, 100, 50], ImageProcessor::fit(600, 300, 100, 0));
        $out = ImageProcessor::create()->square(file_get_contents(__DIR__ . '/fixtures/wide.png'), 64);
        $this->assertSame([64, 64], ImageProcessor::dimensions($out));
    }
}
