<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Contracts\Service\ResetInterface;

class BlurPlaceholderGenerator implements ResetInterface
{
    /** @var array<string, string> */
    private array $cache = [];

    public function __construct(
        private readonly ImageSourceInterface $imageSource,
        private readonly string $cacheDirectory,
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

        $cachePath = $this->getCachePath($src);

        if (is_file($cachePath)) {
            $dataUri = file_get_contents($cachePath);
            if (false !== $dataUri) {
                $this->cache[$src] = $dataUri;

                return $dataUri;
            }
        }

        $dataUri = $this->createBlurDataUri($src);
        $this->writeCache($cachePath, $dataUri);
        $this->cache[$src] = $dataUri;

        return $dataUri;
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    private function createBlurDataUri(string $src): string
    {
        $sourcePath = $this->imageSource->getAbsolutePath($src);
        $imagick = new \Imagick($sourcePath);

        try {
            $imagick->thumbnailImage($this->blurSize, 0);
            $imagick->setImageFormat('JPEG');
            $imagick->setImageCompressionQuality($this->blurQuality);
            $imagick->stripImage();
            $blob = $imagick->getImageBlob();
        } finally {
            $imagick->clear();
        }

        return 'data:image/jpeg;base64,'.base64_encode($blob);
    }

    private function getCachePath(string $src): string
    {
        $hash = sha1($src);

        return \sprintf(
            '%s/blur/%s/%s.txt',
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
