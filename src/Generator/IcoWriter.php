<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use InvalidArgumentException;

/**
 * Writes and reads the ICO container with PNG-encoded entries.
 *
 * Hand-written on purpose: the format is a 6-byte header plus one 16-byte directory entry per image,
 * and every browser since IE9/Vista accepts PNG payloads inside it. No PHP library does just this
 * without pulling in a whole imaging stack, and GD/Imagick cannot write multi-size ICO reliably.
 * Layout reference: ICONDIR (reserved=0, type=1, count) followed by ICONDIRENTRY records.
 */
class IcoWriter
{
    /**
     * @param array<int, string> $pngsBySize size (px, square) => PNG bytes
     */
    public static function build(array $pngsBySize): string
    {
        if (!$pngsBySize) {
            throw new InvalidArgumentException('An ICO needs at least one image');
        }
        ksort($pngsBySize);

        $count = count($pngsBySize);
        $header = pack('vvv', 0, 1, $count);
        # Image data starts after the header and the full directory.
        $offset = 6 + 16 * $count;
        $directory = '';
        $data = '';
        foreach ($pngsBySize as $size => $png) {
            $size = (int) $size;
            if ($size < 1 || $size > 256) {
                throw new InvalidArgumentException("ICO entries must be 1-256 px, got {$size}");
            }
            # A width/height byte of 0 means 256 px.
            $dim = $size === 256 ? 0 : $size;
            $directory .= pack(
                'CCCCvvVV',
                $dim,          # width
                $dim,          # height
                0,             # palette colours (0 = no palette)
                0,             # reserved
                1,             # colour planes
                32,            # bits per pixel
                strlen($png),  # payload size
                $offset        # payload offset from start of file
            );
            $offset += strlen($png);
            $data .= $png;
        }

        return $header . $directory . $data;
    }

    /**
     * Parse an ICO back into its entries. Used by the tests to prove the container is valid, and
     * handy for debugging a served favicon.ico.
     *
     * @return array<int, array{width:int, height:int, bpp:int, size:int, offset:int, data:string}>
     */
    public static function parse(string $ico): array
    {
        if (strlen($ico) < 6) {
            throw new InvalidArgumentException('Too short to be an ICO');
        }
        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        if ($header['reserved'] !== 0 || $header['type'] !== 1) {
            throw new InvalidArgumentException('Not an ICO header');
        }
        $entries = [];
        for ($i = 0; $i < $header['count']; $i++) {
            $raw = substr($ico, 6 + 16 * $i, 16);
            if (strlen($raw) !== 16) {
                throw new InvalidArgumentException("Directory entry {$i} is truncated");
            }
            $e = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbpp/Vsize/Voffset', $raw);
            if ($e['offset'] + $e['size'] > strlen($ico)) {
                throw new InvalidArgumentException("Entry {$i} points past the end of the file");
            }
            $entries[] = [
                'width' => $e['width'] ?: 256,
                'height' => $e['height'] ?: 256,
                'bpp' => $e['bpp'],
                'size' => $e['size'],
                'offset' => $e['offset'],
                'data' => substr($ico, $e['offset'], $e['size']),
            ];
        }
        return $entries;
    }
}
