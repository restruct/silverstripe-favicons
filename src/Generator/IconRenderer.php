<?php

declare(strict_types=1);

namespace Restruct\Favicons\Generator;

use Intervention\Image\Interfaces\ImageInterface;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Core\Injector\Injectable;

/**
 * Draws one square icon variant (size, optional opaque background, optional margin) on the
 * framework's image backend.
 *
 * Used as the callback of DBFile::manipulateImage(), so the backend is whatever Image_Backend the
 * project has configured through the Injector, the result is written to the AssetStore by the
 * framework, and an existing variant is reused instead of redrawn.
 *
 * Why not the backend's own Pad()/ResizedImage(): those keep a transparent source transparent (iOS
 * paints transparency black on a home screen) and cannot add a margin to an image that is already
 * square (the maskable icon needs one). Both are a canvas-and-place operation, which Image_Backend
 * does not offer, so this works on the Intervention image the backend holds, with Intervention's
 * public API only. Silverstripe 5 ships Intervention Image 2, Silverstripe 6 ships version 3; their
 * APIs differ, hence the two branches.
 */
class IconRenderer
{
    use Injectable;

    /**
     * A manipulateImage() callback producing a $size x $size square.
     *
     * @param string|null $background CSS hex colour to fill behind the icon, or null for transparent
     * @param float $padding Margin per side in % of the edge
     * @param object|null $master An already decoded source to draw from (see shrink()); without it
     *                            the backend decodes the stored file, once per variant
     * @return callable(Image_Backend): ?Image_Backend
     */
    public function square(int $size, ?string $background = null, float $padding = 0, ?object $master = null): callable
    {
        return function (Image_Backend $backend) use ($size, $background, $padding, $master): ?Image_Backend {
            $resource = $master ?? $backend->getImageResource();
            if (!$resource) {
                # The backend could not decode the stored source (corrupt PNG). It records that in
                # its own error cache; returning null makes manipulateImage() return null too.
                return null;
            }
            # A clone of the backend, because manipulateImage() clears the resource of the backend it
            # passed in once the callback returns (same pattern as
            # InterventionBackend::createCloneWithResource()).
            $clone = clone $backend;
            $clone->setImageResource($this->draw($resource, $size, $background, $padding));
            return $clone;
        };
    }

    /**
     * Scale a decoded source down IN PLACE to at most $edge px, so every variant is drawn from an
     * image no larger than the largest variant. No copy of the full-size image is made: with a
     * large source that copy would double the memory the decode already costs. Never upscales.
     *
     * Both Intervention majors modify the image itself here (v3 scale(), v2 resize()) and return it.
     *
     * @param object $resource Intervention image (v2 or v3)
     * @return object The same image
     */
    public function shrink(object $resource, int $edge): object
    {
        if ($resource instanceof ImageInterface) {
            # scaleDown() never enlarges; the source is square, so this is $edge x $edge at most.
            return $resource->scaleDown($edge, $edge);
        }
        return $resource->resize($edge, $edge, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
    }

    /**
     * @param object $resource Intervention image (v2 Intervention\Image\Image or v3 ImageInterface)
     * @return object A new Intervention image of the same major
     */
    protected function draw(object $resource, int $size, ?string $background, float $padding): object
    {
        $padding = max(0.0, min(40.0, $padding));
        $inner = max(1, (int) round($size * (1 - 2 * $padding / 100)));
        $background = ($background !== null && trim($background) !== '') ? trim($background) : null;

        if ($resource instanceof ImageInterface) {
            # Intervention 3 (Silverstripe 6). The clone is of the already shrunk source (at most the
            # largest variant), never of the full-size decode. scale() keeps the aspect ratio and may
            # upscale; the source is square, so the result is $inner x $inner. createImage() starts
            # transparent.
            $scaled = (clone $resource)->scale($inner, $inner);
            $canvas = $resource->driver()->createImage($size, $size);
            if ($background !== null) {
                $canvas->fill($background);
            }
            return $canvas->place($scaled, 'center');
        }

        # Intervention 2 (Silverstripe 5), same approach. newImage() fills with the given colour;
        # [0,0,0,0] is fully transparent in v2's RGBA notation (alpha 0..1).
        $scaled = (clone $resource)->resize($inner, $inner, function ($constraint) {
            $constraint->aspectRatio();
        });
        $canvas = $resource->getDriver()->newImage($size, $size, $background ?? [0, 0, 0, 0]);
        return $canvas->insert($scaled, 'center');
    }
}
