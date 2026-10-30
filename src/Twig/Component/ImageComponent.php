<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Twig\Component;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\QualityResolver;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Source\SourcePath;
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

    /**
     * Output quality 1-100: an int for every format, or a map of format => quality for the formats it names
     * (the others keep format_quality / default_quality). Null = configuration.
     *
     * @var int|array<string, int>|null
     */
    public int|array|null $quality = null;

    /**
     * Auto-calculate height from source aspect ratio. Null = use global config.
     */
    public ?bool $autoDimensions = null;

    /**
     * Watermark profile name, false to disable, or null for global default.
     */
    public string|false|null $watermark = null;

    /**
     * Lossless encoding for the output (webp/avif only). Null = use global config.
     */
    public ?bool $lossless = null;

    private bool $svg = false;
    private ?int $resolvedHeight = null;
    private ?int $processingHeight = null;
    private ?string $resolvedWatermark = null;
    private bool $resolvedLossless = false;

    public function __construct(
        private readonly SrcsetGenerator $srcsetGenerator,
        private readonly CachePathResolver $cachePathResolver,
        private readonly BlurPlaceholderGenerator $blurGenerator,
        private readonly ImageMetadataReader $metadataReader,
        private readonly QualityResolver $qualityResolver,
        /** @var list<string> */
        private readonly array $formats,
        private readonly string $routePrefix,
        private readonly bool $blurEnabled,
        private readonly bool $globalAutoDimensions,
        private readonly ?string $defaultWatermark,
        private readonly int $maxWidth,
        private readonly bool $globalLossless,
    ) {
    }

    #[PostMount]
    public function postMount(): void
    {
        $this->src = SourcePath::normalize($this->src);
        $this->svg = SourcePath::isSvg($this->src);

        if ($this->width <= 0) {
            throw new \InvalidArgumentException(\sprintf('The "width" prop is required and must be > 0 on <twig:Image src="%s" />. It is the intrinsic width of the generated image file in pixels — pick the largest size the image will ever render at. See docs for guidance.', $this->src));
        }

        if ($this->width > $this->maxWidth) {
            throw new \InvalidArgumentException(\sprintf('The "width" prop (%d) on <twig:Image src="%s" /> exceeds the configured id_sign_image.max_width (%d). Either lower the width or raise max_width in the bundle config.', $this->width, $this->src, $this->maxWidth));
        }

        $this->qualityResolver->assertValid($this->quality, $this->src);
        CachePathResolver::assertFit($this->fit, \sprintf('<twig:Image src="%s" />', $this->src));
        CachePathResolver::assertHeight($this->height, \sprintf('<twig:Image src="%s" />', $this->src));

        $this->resolvedWatermark = match (true) {
            false === $this->watermark => null,
            \is_string($this->watermark) => $this->watermark,
            default => $this->defaultWatermark,
        };

        $this->resolvedLossless = $this->lossless ?? $this->globalLossless;

        $useAutoDimensions = $this->autoDimensions ?? $this->globalAutoDimensions;

        if (null === $this->height && $useAutoDimensions && !$this->svg) {
            $this->resolvedHeight = $this->metadataReader->calculateHeight($this->src, $this->width);
        } else {
            $this->resolvedHeight = $this->height;
        }

        if ('scale-down' === $this->fit && !$this->svg) {
            $sourceDimensions = $this->metadataReader->resolveScaleDownDimensions($this->src, $this->width, $this->resolvedHeight);

            if (null !== $sourceDimensions) {
                $this->width = $sourceDimensions['width'];
                $this->resolvedHeight = $sourceDimensions['height'];
            }
        }

        $this->processingHeight = null !== $this->fit ? $this->resolvedHeight : null;
    }

    public function isSvg(): bool
    {
        return $this->svg;
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
        // ux-twig-component evaluates every exposed method eagerly, also for the SVG branch of the
        // template, which reads neither sources nor fallbackSrc — skip the signing work there.
        if ($this->svg) {
            return [];
        }

        $sources = [];

        foreach ($this->formats as $format) {
            $quality = $this->qualityResolver->resolve($format, $this->quality);
            $srcset = $this->srcsetGenerator->generateSrcsetString(
                $this->src,
                $this->width,
                $this->processingHeight,
                $this->fit,
                $quality,
                $format,
                $this->resolvedWatermark,
                $this->resolvedLossless,
            );

            $mainPath = $this->cachePathResolver->resolve(
                $this->src,
                $this->width,
                $this->processingHeight,
                $this->fit,
                $quality,
                $format,
                $this->resolvedWatermark,
                $this->resolvedLossless,
            );
            $mainUrl = $this->routePrefix.'/'.CachePathResolver::encodeForUrl($mainPath);

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
        if ($this->svg) {
            return '';
        }

        $fallbackFormat = $this->getFallbackFormat();
        $cachePath = $this->cachePathResolver->resolve(
            $this->src,
            $this->width,
            $this->processingHeight,
            $this->fit,
            $this->qualityResolver->resolve($fallbackFormat, $this->quality),
            $fallbackFormat,
            $this->resolvedWatermark,
            $this->resolvedLossless,
        );

        return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($cachePath);
    }

    #[ExposeInTemplate('svgSrc')]
    public function getSvgSrc(): string
    {
        return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($this->src);
    }

    #[ExposeInTemplate('showBlur')]
    public function getShowBlur(): bool
    {
        return !$this->svg && ($this->blur || $this->blurEnabled);
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
