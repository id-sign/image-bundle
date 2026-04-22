<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Controller;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Service\ImageProcessorInterface;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Service\WatermarkRegistry;
use IdSign\ImageBundle\Source\ImageSourceInterface;
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
    ) {
    }

    public function __invoke(Request $request, string $path): Response
    {
        if ('svg' === strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
            return $this->serveSvg($path);
        }

        return $this->serveRaster($path);
    }

    private function serveSvg(string $src): Response
    {
        if (!$this->imageSource->exists($src)) {
            return new JsonResponse(['error' => 'Source image not found.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->cacheStorage->has($src)) {
            $sourceFile = $this->imageSource->getAbsolutePath($src);
            $this->sourceSizeValidator->assertFits($sourceFile);

            $this->cacheStorage->writeLocked($src, static function (string $tmpPath) use ($sourceFile): void {
                if (!copy($sourceFile, $tmpPath)) {
                    throw new \RuntimeException(\sprintf('Failed to copy SVG source %s to %s', $sourceFile, $tmpPath));
                }
            });
        }

        return $this->buildResponse($src);
    }

    private function serveRaster(string $path): Response
    {
        $params = $this->cachePathResolver->parse($path);
        if (null === $params) {
            return new JsonResponse(['error' => 'Invalid image path.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->urlSigner->verify($params['signature'], $params['src'], $params['width'], $params['height'], $params['fit'], $params['quality'], $params['watermark'])) {
            return new JsonResponse(['error' => 'Invalid signature.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->imageSource->exists($params['src'])) {
            return new JsonResponse(['error' => 'Source image not found.'], Response::HTTP_NOT_FOUND);
        }

        if (null !== $params['watermark'] && !$this->watermarkRegistry->has($params['watermark'])) {
            return new JsonResponse(['error' => \sprintf('Unknown watermark profile: %s', $params['watermark'])], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->cacheStorage->has($path)) {
            $sourceFile = $this->imageSource->getAbsolutePath($params['src']);
            $watermark = null !== $params['watermark'] ? $this->watermarkRegistry->get($params['watermark']) : null;
            $processor = $this->processor;

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
                );
            });
        }

        return $this->buildResponse($path);
    }

    private function buildResponse(string $cachePath): Response
    {
        $response = new BinaryFileResponse($this->cacheStorage->getAbsolutePath($cachePath));
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }
}
