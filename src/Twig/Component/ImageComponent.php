<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Twig\Component;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent('Image', template: '@IdSignImage/components/Image.html.twig')]
class ImageComponent
{
    public string $src = '';
    public int $width = 0;
    public ?int $height = null;
    public ?string $fit = null;
    public bool $blur = false;
    public ?int $quality = null;

    /**
     * Auto-calculate height from source aspect ratio. Null = use global config.
     */
    public ?bool $autoDimensions = null;

    /**
     * Watermark profile name, false to disable, or null for global default.
     */
    public string|false|null $watermark = null;

    private int $resolvedQuality;
    private ?int $resolvedHeight = null;
    private ?string $resolvedWatermark = null;

    public function __construct(
        private readonly SrcsetGenerator $srcsetGenerator,
        private readonly CachePathResolver $cachePathResolver,
        private readonly BlurPlaceholderGenerator $blurGenerator,
        private readonly ImageMetadataReader $metadataReader,
        private readonly int $defaultQuality,
        /** @var list<string> */
        private readonly array $formats,
        private readonly string $routePrefix,
        private readonly bool $blurEnabled,
        private readonly bool $globalAutoDimensions,
        private readonly ?string $defaultWatermark,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->resolvedQuality = $this->quality ?? $this->defaultQuality;

        $this->resolvedWatermark = match (true) {
            false === $this->watermark => null,
            \is_string($this->watermark) => $this->watermark,
            default => $this->defaultWatermark,
        };

        $useAutoDimensions = $this->autoDimensions ?? $this->globalAutoDimensions;

        if (null === $this->height && $useAutoDimensions && !$this->isSvg()) {
            $dimensions = $this->metadataReader->getDimensions($this->src);
            if ($dimensions['width'] > 0) {
                $this->resolvedHeight = (int) round($dimensions['height'] * $this->width / $dimensions['width']);
            }
        } else {
            $this->resolvedHeight = $this->height;
        }
    }

    public function isSvg(): bool
    {
        return 'svg' === strtolower(pathinfo($this->src, \PATHINFO_EXTENSION));
    }

    #[ExposeInTemplate('resolvedHeight')]
    public function getResolvedHeight(): ?int
    {
        return $this->resolvedHeight;
    }

    public function getFallbackFormat(): string
    {
        return FormatNegotiator::getFallbackFormat(pathinfo($this->src, \PATHINFO_EXTENSION));
    }

    /**
     * @return list<array{type: string, srcset: string}>
     */
    #[ExposeInTemplate('sources')]
    public function getSources(): array
    {
        $sources = [];

        foreach ($this->formats as $format) {
            $srcset = $this->srcsetGenerator->generateSrcsetString(
                $this->src,
                $this->width,
                $this->resolvedHeight,
                $this->fit,
                $this->resolvedQuality,
                $format,
                $this->resolvedWatermark,
            );

            $mainPath = $this->cachePathResolver->resolve(
                $this->src,
                $this->width,
                $this->resolvedHeight,
                $this->fit,
                $this->resolvedQuality,
                $format,
                $this->resolvedWatermark,
            );
            $mainUrl = $this->routePrefix.'/'.$mainPath;

            if ('' !== $srcset) {
                $srcset .= ', ';
            }
            $srcset .= $mainUrl.' '.$this->width.'w';

            $sources[] = [
                'type' => FormatNegotiator::getMimeType($format),
                'srcset' => $srcset,
            ];
        }

        return $sources;
    }

    #[ExposeInTemplate('fallbackSrc')]
    public function getFallbackSrc(): string
    {
        $cachePath = $this->cachePathResolver->resolve(
            $this->src,
            $this->width,
            $this->resolvedHeight,
            $this->fit,
            $this->resolvedQuality,
            $this->getFallbackFormat(),
            $this->resolvedWatermark,
        );

        return $this->routePrefix.'/'.$cachePath;
    }

    #[ExposeInTemplate('showBlur')]
    public function getShowBlur(): bool
    {
        return !$this->isSvg() && ($this->blur || $this->blurEnabled);
    }

    #[ExposeInTemplate('blurDataUri')]
    public function getBlurDataUri(): ?string
    {
        if (!$this->getShowBlur()) {
            return null;
        }

        return $this->blurGenerator->generate($this->src);
    }
}
