<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

use IdSign\ImageBundle\Service\UrlSigner;

class CachePathResolver
{
    public function __construct(
        private readonly UrlSigner $urlSigner,
    ) {
    }

    /**
     * Build a path that serves as both URL (after route prefix) and filesystem cache path.
     *
     * Format: {src}/{signature}_{w}_{h}_{fit}_{q}[_lossless][_wm-{profile}].{format}
     */
    public function resolve(
        string $src,
        int $width,
        ?int $height,
        ?string $fit,
        int $quality,
        string $format,
        ?string $watermark = null,
        bool $lossless = false,
    ): string {
        $signature = $this->urlSigner->sign($src, $width, $height, $fit, $quality, $watermark, $lossless);
        $heightPart = $height ?? 'auto';
        $fitPart = $fit ?? 'none';
        $losslessPart = $lossless ? '_lossless' : '';
        $wmPart = null !== $watermark ? '_wm-'.$watermark : '';

        return \sprintf(
            '%s/%s_%d_%s_%s_%d%s%s.%s',
            $src,
            $signature,
            $width,
            $heightPart,
            $fitPart,
            $quality,
            $losslessPart,
            $wmPart,
            $format,
        );
    }

    /**
     * Parse image parameters from a path (URL path after route prefix).
     *
     * @return array{src: string, signature: string, width: int, height: ?int, fit: ?string, quality: int, lossless: bool, watermark: ?string, format: string}|null
     */
    public function parse(string $path): ?array
    {
        if (!preg_match('#^(.+)/([a-f0-9]{16})_(\d+)_(auto|\d+)_(none|cover|contain|scale-down)_(\d+)(_lossless)?(?:_wm-([a-zA-Z0-9_-]+))?\.(avif|webp|jpeg|jpg|png)$#', $path, $matches)) {
            return null;
        }

        return [
            'src' => $matches[1],
            'signature' => $matches[2],
            'width' => (int) $matches[3],
            'height' => 'auto' === $matches[4] ? null : (int) $matches[4],
            'fit' => 'none' === $matches[5] ? null : $matches[5],
            'quality' => (int) $matches[6],
            'lossless' => '_lossless' === $matches[7],
            'watermark' => '' !== $matches[8] ? $matches[8] : null,
            'format' => $matches[9],
        ];
    }
}
