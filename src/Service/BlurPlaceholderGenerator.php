<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Contracts\Service\ResetInterface;

class BlurPlaceholderGenerator implements ResetInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly ImageSourceInterface $imageSource,
        private readonly SourceSizeValidator $sourceSizeValidator,
        private readonly CacheStorageInterface $cacheStorage,
        private readonly int $blurSize,
        private readonly int $blurQuality,
    ) {
    }

    /**
     * Generate a base64-encoded data URI for a blur placeholder.
     */
    public function generate(string $src): string
    {
        if (isset($this->cache[$src])) {
            return $this->cache[$src];
        }

        $cachePath = $src.'/blur.txt';
        $dataUri = $this->readCache($cachePath);

        if (null === $dataUri) {
            // Not written to disk, so a source uploaded later is picked up after reset().
            if (!$this->imageSource->exists($src)) {
                return $this->cache[$src] = '';
            }

            try {
                $dataUri = $this->createBlurDataUri($src);
            } catch (SourceTooLargeException) {
                // Like a missing source: no placeholder, the image URL answers 413.
                return $this->cache[$src] = '';
            }

            // Atomic (tmp file + rename) through the storage: a concurrent reader never sees a truncated file.
            $this->cacheStorage->writeLocked($cachePath, static function (string $tmpPath) use ($dataUri): void {
                file_put_contents($tmpPath, $dataUri);
            });
        }

        return $this->cache[$src] = $dataUri;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * Read without the storage's TTL (see ImageMetadataReader::readCache()). An invalid cache path counts
     * as a miss; an empty/corrupt file is deleted so that writeLocked() rewrites it.
     */
    private function readCache(string $cachePath): ?string
    {
        try {
            $path = $this->cacheStorage->getAbsolutePath($cachePath);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        $dataUri = (string) file_get_contents($path);

        if (!str_starts_with($dataUri, 'data:image/')) {
            $this->cacheStorage->delete($cachePath);

            return null;
        }

        return $dataUri;
    }

    private function createBlurDataUri(string $src): string
    {
        $sourcePath = $this->imageSource->getAbsolutePath($src);
        $this->sourceSizeValidator->assertFits($sourcePath);

        $imagick = new \Imagick();

        try {
            // Header only: a non-JPEG source is decoded in full below, so the pixel limit must be checked first.
            $imagick->pingImage($sourcePath);
            $this->sourceSizeValidator->assertPixelsFit($imagick->getImageWidth(), $imagick->getImageHeight(), $sourcePath);
            $imagick->clear();

            // jpeg:size hints libjpeg to decode at roughly this target, letting it use
            // IDCT scaling (1/2, 1/4, 1/8) instead of decoding the full image. Ineffective
            // for non-JPEG inputs but harmless — Imagick silently ignores the hint.
            $hint = ($this->blurSize * 8).'x';
            $imagick->setOption('jpeg:size', $hint);
            $imagick->readImage($sourcePath);
            ExifOrientation::autoRotate($imagick);
            $imagick->thumbnailImage($this->blurSize, 0);
            // thumbnailImage() keeps the ICC profile; convert the 10 px result so the placeholder has the colours
            // of the processed variant, then strip.
            ColorProfile::normalizeToSrgb($imagick);
            $imagick->setImageFormat('JPEG');
            $imagick->setImageCompressionQuality($this->blurQuality);
            $imagick->stripImage();
            $blob = $imagick->getImageBlob();
        } finally {
            $imagick->clear();
        }

        return 'data:image/jpeg;base64,'.base64_encode($blob);
    }
}
