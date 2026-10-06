<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Contracts\Service\ResetInterface;

class ImageMetadataReader implements ResetInterface
{
    /** @var array<string, array{width: int, height: int}> */
    private array $cache = [];

    public function __construct(
        private readonly ImageSourceInterface $imageSource,
        private readonly SourceSizeValidator $sourceSizeValidator,
        private readonly string $cacheDirectory,
        private readonly ?int $filePermissions,
        private readonly int $directoryPermissions,
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

        $cachePath = $this->getCachePath($src);

        if (is_file($cachePath)) {
            $data = file_get_contents($cachePath);
            if (false !== $data) {
                /** @var array{width: int, height: int} $dimensions */
                $dimensions = json_decode($data, true);
                $this->cache[$src] = $dimensions;

                return $dimensions;
            }
        }

        $dimensions = $this->readDimensions($src);
        $this->writeCache($cachePath, json_encode($dimensions, \JSON_THROW_ON_ERROR));
        $this->cache[$src] = $dimensions;

        return $dimensions;
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

    private function getCachePath(string $src): string
    {
        return \sprintf('%s/%s/meta.json', $this->cacheDirectory, $src);
    }

    private function writeCache(string $path, string $content): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, $this->directoryPermissions, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
        }

        file_put_contents($path, $content);

        if (null !== $this->filePermissions) {
            chmod($path, $this->filePermissions);
        }
    }
}
