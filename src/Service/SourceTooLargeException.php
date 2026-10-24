<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

/**
 * A source image exceeds max_source_bytes or max_source_pixels. The controller answers 413, the render-time
 * readers (metadata, blur) treat the source like a missing one.
 */
class SourceTooLargeException extends \RuntimeException
{
    public static function forBytes(int $size, int $max, string $absolutePath): self
    {
        return new self(\sprintf('Source file exceeds configured max_source_bytes: %d > %d (%s)', $size, $max, $absolutePath));
    }

    public static function forPixels(int $width, int $height, int $max, string $absolutePath): self
    {
        return new self(\sprintf('Source image exceeds configured max_source_pixels: %dx%d = %d > %d (%s)', $width, $height, $width * $height, $max, $absolutePath));
    }

    public static function forFrames(int $frames, int $pixels, int $max, string $absolutePath): self
    {
        return new self(\sprintf('Source image exceeds configured max_source_pixels: %d frames, %d pixels in total > %d (%s)', $frames, $pixels, $max, $absolutePath));
    }
}
