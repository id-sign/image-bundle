<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;

class SrcsetGenerator
{
    /**
     * @param list<int> $deviceSizes
     */
    public function __construct(
        private readonly CachePathResolver $cachePathResolver,
        private readonly array $deviceSizes,
        private readonly string $routePrefix,
    ) {
    }

    /**
     * Generate srcset entries for an image.
     *
     * Only includes breakpoints <= the specified width.
     *
     * @return list<array{url: string, width: int}>
     */
    public function generate(
        string $src,
        int $width,
        ?int $height,
        ?string $fit,
        int $quality,
        string $format,
        ?string $watermark = null,
    ): array {
        $aspectRatio = (null !== $height && $width > 0) ? $height / $width : null;
        $entries = [];

        foreach ($this->deviceSizes as $breakpoint) {
            if ($breakpoint > $width) {
                continue;
            }

            $breakpointHeight = null !== $aspectRatio ? (int) round($breakpoint * $aspectRatio) : null;
            $cachePath = $this->cachePathResolver->resolve($src, $breakpoint, $breakpointHeight, $fit, $quality, $format, $watermark);

            $entries[] = [
                'url' => $this->routePrefix.'/'.$cachePath,
                'width' => $breakpoint,
            ];
        }

        return $entries;
    }

    /**
     * Generate srcset attribute string.
     */
    public function generateSrcsetString(
        string $src,
        int $width,
        ?int $height,
        ?string $fit,
        int $quality,
        string $format,
        ?string $watermark = null,
    ): string {
        $entries = $this->generate($src, $width, $height, $fit, $quality, $format, $watermark);

        return implode(', ', array_map(
            static fn (array $entry): string => $entry['url'].' '.$entry['width'].'w',
            $entries,
        ));
    }
}
