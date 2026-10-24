<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

/**
 * Runtime safety net against oversized sources and decompression bombs; upload validation remains the
 * application's job. Both limits are checked before Imagick decodes anything.
 */
class SourceSizeValidator
{
    public function __construct(
        private readonly int $maxBytes,
        private readonly int $maxPixels = 0,
    ) {
    }

    /**
     * @throws SourceTooLargeException when the file exceeds the configured byte limit
     */
    public function assertFits(string $absolutePath): void
    {
        if (0 === $this->maxBytes) {
            return;
        }

        $size = @filesize($absolutePath);
        if (false === $size) {
            // File existence is the caller's responsibility; silently skip here.
            return;
        }

        if ($size > $this->maxBytes) {
            throw SourceTooLargeException::forBytes($size, $this->maxBytes, $absolutePath);
        }
    }

    /**
     * Pixel count from the header (callers have it from the pingImage() they do anyway). The byte limit alone
     * lets a highly compressed 100 MP JPEG (~15 MB) or a flat 30000x30000 PNG (a few hundred kB) through,
     * and decoding those needs gigabytes — above ImageMagick's memory policy it falls back to a disk-backed
     * pixel cache that takes seconds to minutes per variant while holding the directory lock.
     *
     * @throws SourceTooLargeException when width × height exceeds the configured pixel limit
     */
    public function assertPixelsFit(int $width, int $height, string $absolutePath): void
    {
        if (0 !== $this->maxPixels && $width * $height > $this->maxPixels) {
            throw SourceTooLargeException::forPixels($width, $height, $this->maxPixels, $absolutePath);
        }
    }

    /**
     * The pixel limit for a pinged image, every frame counted. pingImage() leaves the iterator on the LAST frame, so
     * checking the current frame would let a GIF, APNG, animated WebP or multi-page TIFF with a 1×1 last frame
     * through while readImage() decodes all of them (25 kB of GIF → 500 MB). Leaves the iterator on the first frame,
     * the one the callers read.
     *
     * @throws SourceTooLargeException when the frames together exceed the configured pixel limit
     */
    public function assertPingedFits(\Imagick $pinged, string $absolutePath): void
    {
        $pinged->setFirstIterator();

        if (0 === $this->maxPixels) {
            return;
        }

        $pixels = 0;
        $frames = 0;

        do {
            $pixels += $pinged->getImageWidth() * $pinged->getImageHeight();
            ++$frames;
        } while ($pinged->nextImage());

        $pinged->setFirstIterator();

        if ($pixels <= $this->maxPixels) {
            return;
        }

        throw 1 === $frames ? SourceTooLargeException::forPixels($pinged->getImageWidth(), $pinged->getImageHeight(), $this->maxPixels, $absolutePath) : SourceTooLargeException::forFrames($frames, $pixels, $this->maxPixels, $absolutePath);
    }
}
