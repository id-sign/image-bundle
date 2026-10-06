<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

/**
 * Single source of the EXIF orientation rule shared by processing, metadata and blur placeholder.
 */
final class ExifOrientation
{
    /**
     * @throws \ImagickException
     */
    public static function autoRotate(\Imagick $imagick): void
    {
        match ($imagick->getImageOrientation()) {
            \Imagick::ORIENTATION_BOTTOMRIGHT => $imagick->rotateImage(new \ImagickPixel('none'), 180),
            \Imagick::ORIENTATION_RIGHTTOP => $imagick->rotateImage(new \ImagickPixel('none'), 90),
            \Imagick::ORIENTATION_LEFTBOTTOM => $imagick->rotateImage(new \ImagickPixel('none'), -90),
            default => null,
        };

        $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }

    /**
     * Whether autoRotate() swaps width and height; works on a pinged image except WebP, whose ping skips EXIF.
     */
    public static function isQuarterTurn(\Imagick $imagick): bool
    {
        return \in_array($imagick->getImageOrientation(), [\Imagick::ORIENTATION_RIGHTTOP, \Imagick::ORIENTATION_LEFTBOTTOM], true);
    }
}
