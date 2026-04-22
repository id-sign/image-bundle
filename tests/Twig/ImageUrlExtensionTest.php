<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Twig;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\ImageUrlGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Twig\ImageUrlExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ImageUrlExtensionTest extends TestCase
{
    private ImageUrlExtension $extension;
    private RequestStack $requestStack;
    private ImageMetadataReader&\PHPUnit\Framework\MockObject\Stub $metadataReader;

    protected function setUp(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $negotiator = new FormatNegotiator(['avif', 'webp']);
        $generator = new ImageUrlGenerator($resolver, $negotiator, '/_image');

        $this->metadataReader = $this->createStub(ImageMetadataReader::class);
        $this->requestStack = new RequestStack();

        $this->extension = new ImageUrlExtension(
            $generator,
            $this->metadataReader,
            $this->requestStack,
            80,
            null,
            false,
            4096,
            false,
        );
    }

    public function testExplicitFormatIgnoresRequest(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/avif']);
        $this->requestStack->push($request);

        $url = $this->extension->imageUrl('photo.jpg', 800, format: 'webp');

        self::assertStringEndsWith('.webp', $url);
    }

    public function testNegotiatesFormatFromRequest(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp']);
        $this->requestStack->push($request);

        $url = $this->extension->imageUrl('photo.jpg', 800);

        self::assertStringEndsWith('.avif', $url);
    }

    public function testFallsBackToWebpWithoutRequest(): void
    {
        $url = $this->extension->imageUrl('photo.jpg', 800);

        self::assertStringEndsWith('.webp', $url);
    }

    public function testFallsBackToOriginalFormatWhenNoModernSupport(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/jpeg']);
        $this->requestStack->push($request);

        $url = $this->extension->imageUrl('photo.jpg', 800);

        self::assertStringEndsWith('.jpeg', $url);
    }

    public function testWatermarkProfile(): void
    {
        $url = $this->extension->imageUrl('photo.jpg', 800, format: 'webp', watermark: 'copyright');

        self::assertStringContainsString('_wm-copyright', $url);
    }

    public function testWatermarkFalseDisablesDefault(): void
    {
        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $this->metadataReader,
            $this->requestStack,
            80,
            'copyright',
            false,
            4096,
            false,
        );

        $url = $extension->imageUrl('photo.jpg', 800, format: 'webp', watermark: false);

        self::assertStringNotContainsString('_wm-', $url);
    }

    public function testDefaultWatermarkAppliedWhenNull(): void
    {
        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $this->metadataReader,
            $this->requestStack,
            80,
            'copyright',
            false,
            4096,
            false,
        );

        $url = $extension->imageUrl('photo.jpg', 800, format: 'webp');

        self::assertStringContainsString('_wm-copyright', $url);
    }

    public function testDefaultQualityUsedWhenNull(): void
    {
        $url1 = $this->extension->imageUrl('photo.jpg', 800, format: 'webp');
        $url2 = $this->extension->imageUrl('photo.jpg', 800, format: 'webp', quality: 80);

        self::assertSame($url1, $url2);
    }

    public function testExplicitQualityOverridesDefault(): void
    {
        $url1 = $this->extension->imageUrl('photo.jpg', 800, format: 'webp');
        $url2 = $this->extension->imageUrl('photo.jpg', 800, format: 'webp', quality: 50);

        self::assertNotSame($url1, $url2);
    }

    public function testAutoDimensionsCalculatesHeightWithFit(): void
    {
        $this->metadataReader->method('calculateHeight')
            ->willReturn(600);

        $url = $this->extension->imageUrl('photo.jpg', 800, fit: 'contain', autoDimensions: true, format: 'webp');

        self::assertStringContainsString('_600_', $url);
    }

    public function testAutoDimensionsWithoutFitDoesNotAffectUrl(): void
    {
        $this->metadataReader->method('calculateHeight')
            ->willReturn(600);

        $url = $this->extension->imageUrl('photo.jpg', 800, autoDimensions: true, format: 'webp');

        self::assertStringNotContainsString('_600_', $url);
    }

    public function testHeightWithoutFitDoesNotAffectUrl(): void
    {
        $url = $this->extension->imageUrl('photo.jpg', 800, height: 400, format: 'webp');

        self::assertStringNotContainsString('_400_', $url);
    }

    public function testHeightWithFitAffectsUrl(): void
    {
        $url = $this->extension->imageUrl('photo.jpg', 800, height: 400, fit: 'cover', format: 'webp');

        self::assertStringContainsString('_400_', $url);
    }

    public function testAutoDimensionsSkippedWhenHeightProvided(): void
    {
        $metadataReader = $this->createMock(ImageMetadataReader::class);
        $metadataReader->expects(self::never())->method('calculateHeight');

        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $metadataReader,
            $this->requestStack,
            80,
            null,
            false,
            4096,
            false,
        );

        $url = $extension->imageUrl('photo.jpg', 800, height: 400, fit: 'contain', autoDimensions: true, format: 'webp');

        self::assertStringContainsString('_400_', $url);
    }

    public function testAutoDimensionsSkippedForSvg(): void
    {
        $metadataReader = $this->createMock(ImageMetadataReader::class);
        $metadataReader->expects(self::never())->method('calculateHeight');

        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $metadataReader,
            $this->requestStack,
            80,
            null,
            true,
            4096,
            false,
        );

        $url = $extension->imageUrl('icons/logo.svg', 120, format: 'webp');

        self::assertStringStartsWith('/_image/', $url);
    }

    public function testGlobalAutoDimensionsRespected(): void
    {
        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $this->metadataReader,
            $this->requestStack,
            80,
            null,
            true,
            4096,
            false,
        );

        $this->metadataReader->method('calculateHeight')
            ->willReturn(600);

        $url = $extension->imageUrl('photo.jpg', 800, fit: 'contain', format: 'webp');

        self::assertStringContainsString('_600_', $url);
    }

    public function testRegistersFunction(): void
    {
        $functions = $this->extension->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('image_url', $functions[0]->getName());
    }

    public function testImageUrlThrowsWhenWidthIsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->extension->imageUrl('photo.jpg', 0);
    }

    public function testImageUrlThrowsWhenWidthExceedsMaxWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_width');

        $this->extension->imageUrl('photo.jpg', 10_000);
    }

    public function testLosslessParameterAddsLosslessSegment(): void
    {
        $url = $this->extension->imageUrl('icon.png', 800, format: 'webp', lossless: true);

        self::assertStringContainsString('_lossless', $url);
    }

    public function testLosslessFalseDoesNotAddSegment(): void
    {
        $url = $this->extension->imageUrl('photo.jpg', 800, format: 'webp', lossless: false);

        self::assertStringNotContainsString('_lossless', $url);
    }

    public function testGlobalLosslessAppliesWhenParamIsNull(): void
    {
        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $this->metadataReader,
            $this->requestStack,
            80,
            null,
            false,
            4096,
            true, // global lossless on
        );

        $url = $extension->imageUrl('icon.png', 800, format: 'webp');

        self::assertStringContainsString('_lossless', $url);
    }

    public function testLosslessParameterOverridesGlobal(): void
    {
        $extension = new ImageUrlExtension(
            $this->createUrlGenerator(),
            $this->metadataReader,
            $this->requestStack,
            80,
            null,
            false,
            4096,
            true, // global lossless on
        );

        $url = $extension->imageUrl('icon.png', 800, format: 'webp', lossless: false);

        self::assertStringNotContainsString('_lossless', $url);
    }

    private function createUrlGenerator(): ImageUrlGenerator
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $negotiator = new FormatNegotiator(['avif', 'webp']);

        return new ImageUrlGenerator($resolver, $negotiator, '/_image');
    }
}
