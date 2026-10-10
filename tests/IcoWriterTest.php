<?php

namespace Restruct\Favicons\Tests;

use InvalidArgumentException;
use Restruct\Favicons\Generator\IcoWriter;
use Restruct\Favicons\Generator\ImageProcessor;
use SilverStripe\Dev\SapphireTest;

class IcoWriterTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testBuildProducesAValidContainerWithPngEntries()
    {
        $src = file_get_contents(__DIR__ . '/fixtures/icon.png');
        $pngs = [];
        foreach ([48, 16, 32] as $size) {
            $pngs[$size] = ImageProcessor::create()->square($src, $size);
        }
        $ico = IcoWriter::build($pngs);

        # Header: reserved 0, type 1 (icon), count 3
        $this->assertSame([1 => 0, 2 => 1, 3 => 3], unpack('v3', substr($ico, 0, 6)));

        $entries = IcoWriter::parse($ico);
        $this->assertCount(3, $entries);
        # Entries are written smallest first, whatever order they were passed in
        $this->assertSame([16, 32, 48], array_column($entries, 'width'));
        $offset = 6 + 16 * 3;
        foreach ($entries as $entry) {
            $this->assertSame($entry['width'], $entry['height']);
            $this->assertSame(32, $entry['bpp']);
            $this->assertSame($offset, $entry['offset'], 'payloads are contiguous after the directory');
            $offset += $entry['size'];
            $this->assertStringStartsWith("\x89PNG", $entry['data']);
            $this->assertSame([$entry['width'], $entry['width']], ImageProcessor::dimensions($entry['data']));
        }
        $this->assertSame(strlen($ico), $offset, 'no trailing bytes');
    }

    public function testSize256IsEncodedAsZero()
    {
        $png = ImageProcessor::create()->square(file_get_contents(__DIR__ . '/fixtures/icon.png'), 256);
        $ico = IcoWriter::build([256 => $png]);
        # The width byte of the first directory entry
        $this->assertSame(0, ord($ico[6]));
        $this->assertSame(256, IcoWriter::parse($ico)[0]['width']);
    }

    public function testRejectsEmptyInput()
    {
        $this->expectException(InvalidArgumentException::class);
        IcoWriter::build([]);
    }

    public function testRejectsOversizedEntries()
    {
        $this->expectException(InvalidArgumentException::class);
        IcoWriter::build([512 => 'x']);
    }

    public function testParseRejectsTruncatedFiles()
    {
        $ico = IcoWriter::build([16 => str_repeat('x', 50)]);
        $this->expectException(InvalidArgumentException::class);
        IcoWriter::parse(substr($ico, 0, 40));
    }
}
