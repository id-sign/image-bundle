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
}
