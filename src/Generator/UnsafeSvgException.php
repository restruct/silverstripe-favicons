<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use RuntimeException;

/**
 * Thrown inside SvgSanitiser when something forces the whole document to be refused rather than
 * one element or attribute dropped; forRasterising() turns it into null.
 */
class UnsafeSvgException extends RuntimeException
{
}
