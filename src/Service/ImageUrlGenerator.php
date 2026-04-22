<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;
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
        return $this->routePrefix.'/'.$this->cachePathResolver->resolve($src, $width, $height, $fit, $quality, $format, $watermark, $lossless);
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
