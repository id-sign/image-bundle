<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Twig;

use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\ImageUrlGenerator;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImageUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageUrlGenerator $urlGenerator,
        private readonly ImageMetadataReader $metadataReader,
        private readonly RequestStack $requestStack,
        private readonly int $defaultQuality,
        private readonly ?string $defaultWatermark,
        private readonly bool $globalAutoDimensions,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_url', $this->imageUrl(...)),
        ];
    }

    public function imageUrl(
        string $src,
        int $width,
        ?int $height = null,
        ?string $fit = null,
        ?int $quality = null,
        ?string $format = null,
        string|false|null $watermark = null,
        ?bool $autoDimensions = null,
    ): string {
        $resolvedQuality = $quality ?? $this->defaultQuality;

        $resolvedWatermark = match (true) {
            false === $watermark => null,
            \is_string($watermark) => $watermark,
            default => $this->defaultWatermark,
        };

        $resolvedHeight = $height;

        if (null === $height && ($autoDimensions ?? $this->globalAutoDimensions) && 'svg' !== strtolower(pathinfo($src, \PATHINFO_EXTENSION))) {
            $resolvedHeight = $this->metadataReader->calculateHeight($src, $width);
        }

        $processingHeight = null !== $fit ? $resolvedHeight : null;

        if (null !== $format) {
            return $this->urlGenerator->generate($src, $width, $processingHeight, $fit, $resolvedQuality, $format, $resolvedWatermark);
        }

        $request = $this->requestStack->getCurrentRequest();

        if (null !== $request) {
            return $this->urlGenerator->generateFromRequest($request, $src, $width, $processingHeight, $fit, $resolvedQuality, $resolvedWatermark);
        }

        return $this->urlGenerator->generate($src, $width, $processingHeight, $fit, $resolvedQuality, 'webp', $resolvedWatermark);
    }
}
