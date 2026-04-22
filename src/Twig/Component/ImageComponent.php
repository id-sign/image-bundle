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
    private ?int $processingHeight = null;
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
        private readonly int $maxWidth,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        if ($this->width <= 0) {
            throw new \InvalidArgumentException(\sprintf('The "width" prop is required and must be > 0 on <twig:Image src="%s" />. It is the intrinsic width of the generated image file in pixels — pick the largest size the image will ever render at. See docs for guidance.', $this->src));
        }

        if ($this->width > $this->maxWidth) {
            throw new \InvalidArgumentException(\sprintf('The "width" prop (%d) on <twig:Image src="%s" /> exceeds the configured id_sign_image.max_width (%d). Either lower the width or raise max_width in the bundle config.', $this->width, $this->src, $this->maxWidth));
        }

        $this->resolvedQuality = $this->quality ?? $this->defaultQuality;

        $this->resolvedWatermark = match (true) {
            false === $this->watermark => null,
            \is_string($this->watermark) => $this->watermark,
            default => $this->defaultWatermark,
        };

        $useAutoDimensions = $this->autoDimensions ?? $this->globalAutoDimensions;

        if (null === $this->height && $useAutoDimensions && !$this->isSvg()) {
            $this->resolvedHeight = $this->metadataReader->calculateHeight($this->src, $this->width);
        } else {
            $this->resolvedHeight = $this->height;
        }

        $this->processingHeight = null !== $this->fit ? $this->resolvedHeight : null;
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
                $this->processingHeight,
                $this->fit,
                $this->resolvedQuality,
                $format,
                $this->resolvedWatermark,
            );

            $mainPath = $this->cachePathResolver->resolve(
                $this->src,
                $this->width,
                $this->processingHeight,
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
            $this->processingHeight,
            $this->fit,
            $this->resolvedQuality,
            $this->getFallbackFormat(),
            $this->resolvedWatermark,
        );

        return $this->routePrefix.'/'.$cachePath;
    }

    #[ExposeInTemplate('svgSrc')]
    public function getSvgSrc(): string
    {
        return $this->routePrefix.'/'.$this->src;
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
