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
        private readonly string $cacheDirectory,
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
        $imagick = new \Imagick($sourcePath);

        try {
            return [
                'width' => $imagick->getImageWidth(),
                'height' => $imagick->getImageHeight(),
            ];
        } finally {
            $imagick->clear();
        }
    }

    private function getCachePath(string $src): string
    {
        $hash = sha1($src);

        return \sprintf(
            '%s/meta/%s/%s.json',
            $this->cacheDirectory,
            substr($hash, 0, 2),
            $hash,
        );
    }

    private function writeCache(string $path, string $content): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
        }

        file_put_contents($path, $content);
    }
}
