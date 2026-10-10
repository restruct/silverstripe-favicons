<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Dev\TestOnly;

/**
 * Stands in for the real rasterisers: records every SVG it is handed and returns a configurable
 * result, so tests can see what reaches a renderer and how often, on any machine.
 *
 * The statics are reset by the tests that use it (FaviconServiceTest::setUp), because SapphireTest
 * restores Config between tests but not plain statics.
 */
class FavFakeRasteriser extends SvgRasteriser implements TestOnly
{
    /** @var string[] */
    public static array $received = [];

    /** PNG to return, or null to simulate a failing renderer */
    public static ?string $result = null;

    /** Times FaviconService asked for the in-progress marker lifetime */
    public static int $busyTtlCalls = 0;

    public static function reset(): void
    {
        static::$received = [];
        static::$result = null;
        static::$busyTtlCalls = 0;
    }

    public function getBusyTtl(): int
    {
        static::$busyTtlCalls++;
        return parent::getBusyTtl();
    }

    public function getAvailable(): ?string
    {
        return 'fake';
    }

    public function rasterise(string $svg, int $size): ?string
    {
        static::$received[] = $svg;
        return static::$result;
    }
}
