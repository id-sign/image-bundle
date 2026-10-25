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
        private readonly int $maxWidth,
    ) {
    }

    /**
     * Generate an optimized image URL for a specific format.
     *
     * The controller serves only the configured formats and the source's fallback format (the signature does not
     * cover the format, see FormatNegotiator::isOffered()); any other $format would yield a URL that answers 404,
     * so it is refused here, where the mistake is visible. Without a format — nothing to negotiate from: console
     * command, e-mail rendered in a worker — the URL is webp when configured, else the source's fallback format.
     *
     * The other rules are the component's: $width must be 1…max_width (the controller trusts the signature, so the
     * cap has to sit where URLs are made), and $height is a processing dimension only together with $fit — without
     * one the server resizes by width and keeps the aspect ratio, exactly like <twig:Image> and image_url().
     *
     * @throws \InvalidArgumentException when $width is not within 1…max_width, or $format is one the controller
     *                                   would not serve for this source
     */
    public function generate(
        string $src,
        int $width,
        ?int $height = null,
        ?string $fit = null,
        int $quality = 80,
        ?string $format = null,
        ?string $watermark = null,
        bool $lossless = false,
    ): string {
        $src = SourcePath::normalize($src);

        if ($width <= 0) {
            throw new \InvalidArgumentException(\sprintf('ImageUrlGenerator requires width > 0 (got %d for src "%s").', $width, $src));
        }

        if ($width > $this->maxWidth) {
            throw new \InvalidArgumentException(\sprintf('ImageUrlGenerator width (%d) for src "%s" exceeds the configured id_sign_image.max_width (%d).', $width, $src, $this->maxWidth));
        }

        // Like <twig:Image>: an SVG is served as-is, there is no variant to sign. A signed raster URL would make the
        // controller rasterize an untrusted SVG, and its variants directory would collide with the passthrough file.
        if (SourcePath::isSvg($src)) {
            return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($src);
        }

        if (null === $format) {
            $format = $this->defaultFormat($src);
        } elseif (!$this->formatNegotiator->isOffered($format, $src)) {
            throw new \InvalidArgumentException(\sprintf('Format "%s" is not generated for "%s": the controller serves only the configured "formats" and the source\'s fallback format ("%s"), any other extension answers 404.', $format, $src, FormatNegotiator::getFallbackFormat(pathinfo($src, \PATHINFO_EXTENSION))));
        }

        // Height without fit is a layout hint in the component and image_url(); a signed "_800_600_none_" would make
        // the processor resize to exactly that box and distort the image.
        $processingHeight = null !== $fit ? $height : null;

        return $this->routePrefix.'/'.CachePathResolver::encodeForUrl($this->cachePathResolver->resolve($src, $width, $processingHeight, $fit, $quality, $format, $watermark, $lossless));
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

    /**
     * webp is what image_url() has always returned without a request — kept while it is configured; otherwise the
     * source's fallback format, which the controller serves whatever the configuration says.
     */
    private function defaultFormat(string $src): string
    {
        if ($this->formatNegotiator->isOffered('webp', $src)) {
            return 'webp';
        }

        return FormatNegotiator::getFallbackFormat(pathinfo($src, \PATHINFO_EXTENSION));
    }
}
