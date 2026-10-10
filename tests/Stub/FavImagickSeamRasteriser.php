<?php

namespace Restruct\Favicons\Tests\Stub;

use Restruct\Favicons\Generator\SvgRasteriser;
use SilverStripe\Dev\TestOnly;

/**
 * The real viaImagick() code path driven against a recorder instead of ext-imagick, which the test
 * machines may not have: proves which density and limits viaImagick() asks ImageMagick for.
 *
 * Statics are reset by the tests that use it, because SapphireTest does not restore plain statics.
 */
class FavImagickSeamRasteriser extends SvgRasteriser implements TestOnly
{
    /** @var array<int, array{0:string, 1:array<int, mixed>}> */
    public static array $calls = [];

    /** @var int[] */
    public static array $limitSizes = [];

    public static bool $imagickAvailable = true;

    public static function reset(): void
    {
        static::$calls = [];
        static::$limitSizes = [];
        static::$imagickAvailable = true;
    }

    public function imagickCanReadSvg(): bool
    {
        return static::$imagickAvailable;
    }

    protected function createImagick(): object
    {
        return new class {
            public function __call(string $name, array $args)
            {
                FavImagickSeamRasteriser::$calls[] = [$name, $args];
                if ($name === 'getImageBlob') {
                    return (string) file_get_contents(dirname(__DIR__) . '/fixtures/small.png');
                }
                return true;
            }
        };
    }

    protected function applyImagickLimits(int $size): array
    {
        static::$limitSizes[] = $size;
        return [];
    }

    /**
     * @return array<int, mixed>|null Arguments of the first call to $method
     */
    public static function argsOf(string $method): ?array
    {
        foreach (static::$calls as [$name, $args]) {
            if ($name === $method) {
                return $args;
            }
        }
        return null;
    }
}
