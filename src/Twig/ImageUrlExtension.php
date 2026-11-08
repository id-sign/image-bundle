<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Twig;

use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\ImageUrlGenerator;
use IdSign\ImageBundle\Source\SourcePath;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ImageUrlExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImageUrlGenerator $urlGenerator,
        private readonly ImageMetadataReader $metadataReader,
        private readonly RequestStack $requestStack,
        private readonly ?string $defaultWatermark,
        private readonly bool $globalAutoDimensions,
        private readonly bool $globalLossless,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image_url', $this->imageUrl(...)),
        ];
    }

    /**
     * @param int|array<string, int>|null $quality resolved by the generator once the format is known
     */
    public function imageUrl(
        string $src,
        int $width,
        ?int $height = null,
        ?string $fit = null,
        int|array|null $quality = null,
        ?string $format = null,
        string|false|null $watermark = null,
        ?bool $autoDimensions = null,
        ?bool $lossless = null,
    ): string {
        $src = SourcePath::normalize($src);

        // Before the metadata I/O below; the generator runs the same checks again for its direct callers.
        $this->urlGenerator->assertArguments($src, $width, $height, $fit, $quality, 'image_url()');

        $resolvedWatermark = match (true) {
            false === $watermark => null,
            \is_string($watermark) => $watermark,
            default => $this->defaultWatermark,
        };

        $resolvedLossless = $lossless ?? $this->globalLossless;

        $resolvedHeight = $height;
        $isSvg = SourcePath::isSvg($src);
        $calculateHeightFromSource = null === $height && ($autoDimensions ?? $this->globalAutoDimensions) && !$isSvg;

        if ($calculateHeightFromSource) {
            $resolvedHeight = $this->metadataReader->calculateHeight($src, $width);
        }

        if ('scale-down' === $fit && !$isSvg) {
            $sourceDimensions = $this->metadataReader->resolveScaleDownDimensions($src, $width, $resolvedHeight);

            if (null !== $sourceDimensions) {
                $width = $sourceDimensions['width'];
                $resolvedHeight = $sourceDimensions['height'];
            }
        }

        $processingHeight = null !== $fit ? $resolvedHeight : null;

        if (null === $format) {
            $request = $this->requestStack->getCurrentRequest();

            if (null !== $request) {
                return $this->urlGenerator->generateFromRequest($request, $src, $width, $processingHeight, $fit, $quality, $resolvedWatermark, $resolvedLossless);
            }
        }

        // Explicit format (validated by the generator), or no request to negotiate from — the generator then picks
        // a format the controller serves.
        return $this->urlGenerator->generate($src, $width, $processingHeight, $fit, $quality, $format, $resolvedWatermark, $resolvedLossless);
    }
}
