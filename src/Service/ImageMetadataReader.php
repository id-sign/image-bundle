<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Contracts\Service\ResetInterface;

class ImageMetadataReader implements ResetInterface
{
    /** @var array<string, array{width: int, height: int}> */
    private array $cache = [];

    public function __construct(
        private readonly ImageSourceInterface $imageSource,
        private readonly SourceSizeValidator $sourceSizeValidator,
        private readonly CacheStorageInterface $cacheStorage,
    ) {
    }

    /**
     * Get the dimensions of a source image.
     *
     * @return array{width: int, height: int}
     */
    public function getDimensions(string $src): array
    {
        if (isset($this->cache[$src])) {
            return $this->cache[$src];
        }

        $cachePath = $src.'/meta.json';
        $dimensions = $this->readCache($cachePath);

        if (null === $dimensions) {
            // Not written to disk, so a source uploaded later is picked up after reset().
            if (!$this->imageSource->exists($src)) {
                return $this->cache[$src] = ['width' => 0, 'height' => 0];
            }

            try {
                $dimensions = $this->readDimensions($src);
            } catch (SourceTooLargeException) {
                // Like a missing source: the page renders without height, the image URL answers 413.
                return $this->cache[$src] = ['width' => 0, 'height' => 0];
            }

            $json = json_encode($dimensions, \JSON_THROW_ON_ERROR);

            // Atomic (tmp file + rename) through the storage: a concurrent reader never sees a truncated file.
            $this->cacheStorage->writeLocked($cachePath, static function (string $tmpPath) use ($json): void {
                file_put_contents($tmpPath, $json);
            });
        }

        return $this->cache[$src] = $dimensions;
    }

    /**
     * Calculate proportional height for a given target width based on source aspect ratio.
     * Returns null if source dimensions cannot be determined.
     */
    public function calculateHeight(string $src, int $width): ?int
    {
        $dimensions = $this->getDimensions($src);

        if ($dimensions['width'] <= 0) {
            return null;
        }

        return (int) round($dimensions['height'] * $width / $dimensions['width']);
    }

    /**
     * Source dimensions when a scale-down output keeps the source size (mirrors ImagickProcessor::fitScaleDown()),
     * otherwise null. A null height means the box is unbounded vertically.
     *
     * @return array{width: int, height: int}|null
     */
    public function resolveScaleDownDimensions(string $src, int $width, ?int $height): ?array
    {
        $dimensions = $this->getDimensions($src);

        if ($dimensions['width'] <= 0 || $dimensions['width'] > $width) {
            return null;
        }

        if (null !== $height && $dimensions['height'] > $height) {
            return null;
        }

        return $dimensions;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Read without the storage's TTL: dimensions change only when the source file is replaced, and
     * that purges the whole source directory anyway. An invalid cache path ("..", empty segment) counts
     * as a miss; an empty/corrupt file is deleted so that writeLocked() (which re-checks has() under the
     * lock) rewrites it.
     *
     * @return array{width: int, height: int}|null
     */
    private function readCache(string $cachePath): ?array
    {
        try {
            $path = $this->cacheStorage->getAbsolutePath($cachePath);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        $width = \is_array($data) ? $data['width'] ?? null : null;
        $height = \is_array($data) ? $data['height'] ?? null : null;

        if (!\is_int($width) || !\is_int($height)) {
            $this->cacheStorage->delete($cachePath);

            return null;
        }

        return ['width' => $width, 'height' => $height];
    }

    /**
     * @return array{width: int, height: int}
     */
    private function readDimensions(string $src): array
    {
        $sourcePath = $this->imageSource->getAbsolutePath($src);
        $this->sourceSizeValidator->assertFits($sourcePath);

        $imagick = new \Imagick();

        try {
            // pingImage reads only the header — orders of magnitude faster than new Imagick($path),
            // which decodes the full pixel buffer just to learn the dimensions.
            $imagick->pingImage($sourcePath);
            $this->sourceSizeValidator->assertPixelsFit($imagick->getImageWidth(), $imagick->getImageHeight(), $sourcePath);

            // ImageMagick's WebP coder returns from ping before it reads the EXIF chunk, so the orientation is lost.
            if ('WEBP' === $imagick->getImageFormat() && self::hasWebpExifFlag($sourcePath)) {
                $imagick->clear();
                $imagick->readImage($sourcePath);
            }

            $width = $imagick->getImageWidth();
            $height = $imagick->getImageHeight();

            if (ExifOrientation::isQuarterTurn($imagick)) {
                [$width, $height] = [$height, $width];
            }

            return ['width' => $width, 'height' => $height];
        } finally {
            $imagick->clear();
        }
    }

    private static function hasWebpExifFlag(string $path): bool
    {
        $header = (string) file_get_contents($path, false, null, 0, 21);

        return 21 === \strlen($header) && 'VP8X' === substr($header, 12, 4) && 0 !== (\ord($header[20]) & 0x08);
    }
}
