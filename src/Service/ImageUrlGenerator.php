<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Source\SourcePath;
use Symfony\Component\HttpFoundation\Request;

class ImageUrlGenerator
{
    public function __construct(
        private readonly CachePathResolver $cachePathResolver,
        private readonly FormatNegotiator $formatNegotiator,
        private readonly string $routePrefix,
    ) {
    }

    /**
     * Generate an optimized image URL for a specific format.
     */
    public function generate(
        string $src,
        int $width,
        ?int $height = null,
        ?string $fit = null,
        int $quality = 80,
        string $format = 'webp',
        ?string $watermark = null,
        bool $lossless = false,
    ): string {
        $src = SourcePath::normalize($src);

        // Like <twig:Image>: an SVG is served as-is, there is no variant to sign. A signed raster URL would make the
        // controller rasterize an untrusted SVG, and its variants directory would collide with the passthrough file.
        if (SourcePath::isSvg($src)) {
            return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($src);
        }

        return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($this->cachePathResolver->resolve($src, $width, $height, $fit, $quality, $format, $watermark, $lossless));
    }

    /**
     * Generate an optimized image URL, negotiating format from the Request Accept header.
     */
    public function generateFromRequest(
        Request $request,
        string $src,
        int $width,
        ?int $height = null,
        ?string $fit = null,
        int $quality = 80,
        ?string $watermark = null,
        bool $lossless = false,
    ): string {
        $format = $this->formatNegotiator->negotiateFromRequest($request, $src);

        return $this->generate($src, $width, $height, $fit, $quality, $format, $watermark, $lossless);
    }
}
