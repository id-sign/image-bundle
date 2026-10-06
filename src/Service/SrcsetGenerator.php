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
     * Only includes breakpoints strictly < the specified width. The main width itself
     * is appended separately by the caller (ImageComponent), so we skip breakpoints
     * equal to width to avoid duplicate srcset entries. Breakpoint heights round up for
     * contain/scale-down and to nearest for cover, never below 1 px.
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
        bool $lossless = false,
    ): array {
        $bestfit = \in_array($fit, ['contain', 'scale-down'], true);
        $entries = [];

        foreach ($this->deviceSizes as $breakpoint) {
            if ($breakpoint >= $width) {
                continue;
            }

            $breakpointHeight = null;
            if (null !== $height && $width > 0) {
                // Bestfit shrinks to the tighter box side, so a rounded-down height would make the
                // output narrower than its w descriptor; cover crops to the box exactly.
                $exactHeight = $breakpoint * $height / $width;
                $breakpointHeight = max(1, (int) ($bestfit ? ceil($exactHeight) : round($exactHeight)));
            }
            $cachePath = $this->cachePathResolver->resolve($src, $breakpoint, $breakpointHeight, $fit, $quality, $format, $watermark, $lossless);

            $entries[] = [
                'url' => $this->routePrefix.'/'.CachePathResolver::encodeForUrl($cachePath),
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
        bool $lossless = false,
    ): string {
        $entries = $this->generate($src, $width, $height, $fit, $quality, $format, $watermark, $lossless);

        return implode(', ', array_map(
            static fn (array $entry): string => $entry['url'].' '.$entry['width'].'w',
            $entries,
        ));
    }
}
