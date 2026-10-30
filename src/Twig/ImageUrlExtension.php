<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Twig;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\ImageUrlGenerator;
use IdSign\ImageBundle\Service\QualityResolver;
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
        private readonly QualityResolver $qualityResolver,
        private readonly ?string $defaultWatermark,
        private readonly bool $globalAutoDimensions,
        private readonly int $maxWidth,
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

        if ($width <= 0) {
            throw new \InvalidArgumentException(\sprintf('image_url() requires width > 0 (got %d for src "%s").', $width, $src));
        }

        if ($width > $this->maxWidth) {
            throw new \InvalidArgumentException(\sprintf('image_url() width (%d) for src "%s" exceeds the configured id_sign_image.max_width (%d).', $width, $src, $this->maxWidth));
        }

        // Before the metadata I/O below; the generator checks again for its direct callers.
        $this->qualityResolver->assertValid($quality, $src);
        CachePathResolver::assertFit($fit, \sprintf('image_url() for src "%s"', $src));

        $resolvedWatermark = match (true) {
            false === $watermark => null,
            \is_string($watermark) => $watermark,
            default => $this->defaultWatermark,
        };

        $resolvedLossless = $lossless ?? $this->globalLossless;

        $resolvedHeight = $height;
        $isSvg = SourcePath::isSvg($src);

        if (null === $height && ($autoDimensions ?? $this->globalAutoDimensions) && !$isSvg) {
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
