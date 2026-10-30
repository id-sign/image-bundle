<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Controller;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Service\ImageProcessorInterface;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\SourceTooLargeException;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Service\WatermarkRegistry;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use IdSign\ImageBundle\Source\SourcePath;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class ImageController
{
    public function __construct(
        private readonly ImageProcessorInterface $processor,
        private readonly CacheStorageInterface $cacheStorage,
        private readonly CachePathResolver $cachePathResolver,
        private readonly UrlSigner $urlSigner,
        private readonly ImageSourceInterface $imageSource,
        private readonly WatermarkRegistry $watermarkRegistry,
        private readonly SourceSizeValidator $sourceSizeValidator,
        private readonly FormatNegotiator $formatNegotiator,
    ) {
    }

    public function __invoke(Request $request, string $path): Response
    {
        if (SourcePath::isSvg($path)) {
            return $this->serveSvg($path);
        }

        return $this->serveRaster($path);
    }

    /**
     * Cache first, source only on a miss: a hit never touches the source (one stat() locally, a round-trip
     * for a remote source). A cached variant of a deleted source is therefore served until the TTL expires
     * or image:purge / deleteBySource() removes it — exactly what public mode does, where try_files never
     * consults the source either.
     */
    private function serveSvg(string $src): Response
    {
        if (!$this->cacheStorage->has($src)) {
            if (!$this->imageSource->exists($src)) {
                return new JsonResponse(['error' => 'Source image not found.'], Response::HTTP_NOT_FOUND);
            }

            $sourceFile = $this->imageSource->getAbsolutePath($src);

            try {
                $this->sourceSizeValidator->assertFits($sourceFile);
            } catch (SourceTooLargeException) {
                return $this->sourceTooLarge();
            }

            $this->cacheStorage->writeLocked($src, static function (string $tmpPath) use ($sourceFile): void {
                if (!copy($sourceFile, $tmpPath)) {
                    throw new \RuntimeException(\sprintf('Failed to copy SVG source %s to %s', $sourceFile, $tmpPath));
                }
            });
        }

        $response = $this->buildResponse($src, 'image/svg+xml');
        // An SVG can carry <script>; loaded as a document from the application's origin it would run there. The
        // sandbox blocks that, nosniff keeps the type as sent. Covers controller mode and the first (uncached)
        // response in public mode; public-mode cache hits never reach PHP, so the web server adds the same there.
        $response->headers->set('Content-Security-Policy', 'sandbox');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function serveRaster(string $path): Response
    {
        $params = $this->cachePathResolver->parse($path);
        if (null === $params) {
            return new JsonResponse(['error' => 'Invalid image path.'], Response::HTTP_BAD_REQUEST);
        }

        // An SVG is served as-is (serveSvg()); nothing produces a signed raster URL for one (ImageUrlGenerator), and
        // processing it would hand an untrusted SVG to ImageMagick's rasterizer.
        // The signature does not cover the format: only the formats the application would link to are generated.
        if (SourcePath::isSvg($params['src']) || !$this->formatNegotiator->isOffered($params['format'], $params['src'])) {
            return new JsonResponse(['error' => 'Variant not available.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->urlSigner->verify($params['signature'], $params['src'], $params['width'], $params['height'], $params['fit'], $params['quality'], $params['watermark'], $params['lossless'])) {
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_FORBIDDEN);
        }

        // Cache first — see serveSvg().
        if (!$this->cacheStorage->has($path)) {
            if (!$this->imageSource->exists($params['src'])) {
                return new JsonResponse(['error' => 'Source image not found.'], Response::HTTP_NOT_FOUND);
            }

            if (null !== $params['watermark'] && !$this->watermarkRegistry->has($params['watermark'])) {
                return new JsonResponse(['error' => \sprintf('Unknown watermark profile: %s', $params['watermark'])], Response::HTTP_BAD_REQUEST);
            }

            $sourceFile = $this->imageSource->getAbsolutePath($params['src']);
            $watermark = null !== $params['watermark'] ? $this->watermarkRegistry->get($params['watermark']) : null;
            $processor = $this->processor;

            try {
                $this->cacheStorage->writeLocked($path, static function (string $tmpPath) use ($processor, $sourceFile, $params, $watermark): void {
                    $processor->process(
                        $sourceFile,
                        $tmpPath,
                        $params['width'],
                        $params['height'],
                        $params['fit'],
                        $params['format'],
                        $params['quality'],
                        $watermark,
                        $params['lossless'],
                    );
                });
            } catch (SourceTooLargeException) {
                // max_source_bytes / max_source_pixels; the processor checks both before decoding anything.
                return $this->sourceTooLarge();
            }
        }

        return $this->buildResponse($path, FormatNegotiator::getMimeType($params['format']));
    }

    private function sourceTooLarge(): JsonResponse
    {
        return new JsonResponse(['error' => 'Source image exceeds the configured size limits.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
    }

    private function buildResponse(string $cachePath, string $mimeType): Response
    {
        $response = new BinaryFileResponse($this->cacheStorage->getAbsolutePath($cachePath));
        // Explicit: the format is known, and guessing (BinaryFileResponse::prepare() → File::getMimeType()) needs
        // symfony/mime, which the bundle does not require, and sniffs the content — an SVG that starts like HTML
        // would be sent as text/html.
        $response->headers->set('Content-Type', $mimeType);
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }
}
