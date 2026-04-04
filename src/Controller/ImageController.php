<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Controller;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Cache\CacheStorageInterface;
use IdSign\ImageBundle\Service\ImageProcessorInterface;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Service\WatermarkRegistry;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
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
        private readonly string $tmpDir,
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
            return new Response('Source image not found.', Response::HTTP_NOT_FOUND);
        }

        if (!$this->cacheStorage->has($src)) {
            $tmpFile = tempnam($this->tmpDir, 'id_sign_image_');
            if (false === $tmpFile) {
                return new Response('Failed to create temporary file.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            try {
                copy($this->imageSource->getAbsolutePath($src), $tmpFile);
                $this->cacheStorage->write($src, $tmpFile);
            } finally {
                if (is_file($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        }

        return $this->buildResponse($src);
    }

    private function serveRaster(string $path): Response
    {
        $params = $this->cachePathResolver->parse($path);
        if (null === $params) {
            return new Response('Invalid image path.', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->urlSigner->verify($params['signature'], $params['src'], $params['width'], $params['height'], $params['fit'], $params['quality'], $params['watermark'])) {
            return new Response('Invalid signature.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->imageSource->exists($params['src'])) {
            return new Response('Source image not found.', Response::HTTP_NOT_FOUND);
        }

        if (null !== $params['watermark'] && !$this->watermarkRegistry->has($params['watermark'])) {
            return new Response(\sprintf('Unknown watermark profile: %s', $params['watermark']), Response::HTTP_BAD_REQUEST);
        }

        if (!$this->cacheStorage->has($path)) {
            $tmpFile = tempnam($this->tmpDir, 'id_sign_image_');
            if (false === $tmpFile) {
                return new Response('Failed to create temporary file.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            try {
                $sourceFile = $this->imageSource->getAbsolutePath($params['src']);
                $watermark = null !== $params['watermark'] ? $this->watermarkRegistry->get($params['watermark']) : null;

                $this->processor->process(
                    $sourceFile,
                    $tmpFile,
                    $params['width'],
                    $params['height'],
                    $params['fit'],
                    $params['format'],
                    $params['quality'],
                    $watermark,
                );
                $this->cacheStorage->write($path, $tmpFile);
            } finally {
                if (is_file($tmpFile)) {
                    unlink($tmpFile);
                }
            }
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
