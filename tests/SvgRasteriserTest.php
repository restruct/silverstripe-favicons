<?php

namespace Restruct\Favicons\Tests;

use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

class SvgRasteriserTest extends SapphireTest
{
    protected $usesDatabase = false;

    private function svg(string $attrs): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" ' . $attrs . '><rect width="1" height="1"/></svg>';
    }

    public function testDeclaredSize()
    {
        $this->assertSame([64.0, 32.0], SvgRasteriser::declaredSize($this->svg('width="64" height="32"')));
        $this->assertSame([64.0, 32.0], SvgRasteriser::declaredSize($this->svg('width="64px" height="32px"')));
        $this->assertSame([96.0, 48.0], SvgRasteriser::declaredSize($this->svg('width="1in" height="0.5in"')));
        # Relative or missing lengths fall back to the viewBox
        $this->assertSame([100.0, 50.0], SvgRasteriser::declaredSize($this->svg('width="100%" viewBox="0 0 100 50"')));
        $this->assertSame([24.0, 24.0], SvgRasteriser::declaredSize($this->svg('viewBox="0,0,24,24"')));
        # Nothing at all: the CSS default for replaced elements
        $this->assertSame([300.0, 150.0], SvgRasteriser::declaredSize($this->svg('')));
    }

    public function testAbsurdDeclarationsAreRefused()
    {
        $this->assertNull(SvgRasteriser::declaredSize($this->svg('width="20000" height="20000"')));
        $this->assertNull(SvgRasteriser::declaredSize($this->svg('viewBox="0 0 50000 10"')));
        $this->assertNull(SvgRasteriser::declaredSize($this->svg('width="0" height="10"')));
        $this->assertNull(SvgRasteriser::declaredSize($this->svg('width="1e400" height="10"')));
        $this->assertNull(SvgRasteriser::declaredSize('<svg'), 'unparseable');
    }

    public function testDensityTargetsTheMasterSize()
    {
        # 96 dpi renders user units 1:1, so the density scales the raster to the target
        $this->assertEqualsWithDelta(768.0, SvgRasteriser::densityFor([64.0, 64.0], 512), 0.001);
        $this->assertEqualsWithDelta(96.0, SvgRasteriser::densityFor([512.0, 256.0], 512), 0.001);
        $this->assertEqualsWithDelta(9.8304, SvgRasteriser::densityFor([5000.0, 5000.0], 512), 0.001);
        # Clamped at both ends
        $this->assertSame(2400.0, SvgRasteriser::densityFor([1.0, 1.0], 512));
        $this->assertSame(1.0, SvgRasteriser::densityFor([9999.0, 9999.0], 16));
    }

    public function testRasteriseRefusesBeforeRendering()
    {
        $r = SvgRasteriser::create();
        $this->assertNull($r->rasterise($this->svg('width="20000" height="20000"'), 512), 'absurd declared size');
        $this->assertNull($r->rasterise($this->svg('width="64" height="64"'), SvgRasteriser::MAX_OUTPUT_SIZE + 1), 'oversized output');
        $this->assertNull($r->rasterise($this->svg('width="64" height="64"'), 0));
    }

    public function testHangingRsvgConvertIsKilledAtTheDeadline()
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is disabled');
        }
        Config::modify()->set(SvgRasteriser::class, 'rasterisers', ['rsvg-convert']);
        Config::modify()->set(SvgRasteriser::class, 'rsvg_convert_path', __DIR__ . '/fixtures/slow-rsvg.sh');
        Config::modify()->set(SvgRasteriser::class, 'rsvg_timeout', 1);
        $start = microtime(true);
        $png = SvgRasteriser::create()->rasterise($this->svg('width="64" height="64"'), 64);
        $elapsed = microtime(true) - $start;
        $this->assertNull($png);
        $this->assertLessThan(5, $elapsed, 'returned at the deadline, not after the 30 s sleep');
    }

    public function testRsvgConvertExitingEarlyDoesNotWarn()
    {
        if (!function_exists('proc_open') || !is_executable('/usr/bin/false')) {
            $this->markTestSkipped('needs proc_open and /usr/bin/false');
        }
        Config::modify()->set(SvgRasteriser::class, 'rasterisers', ['rsvg-convert']);
        Config::modify()->set(SvgRasteriser::class, 'rsvg_convert_path', '/usr/bin/false');
        # A large body, so the process is gone while stdin is still being written (Broken pipe)
        $big = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><!--' . str_repeat('x', 1024 * 1024) . '--></svg>';
        $this->assertNull(SvgRasteriser::create()->rasterise($big, 64));
    }
}
