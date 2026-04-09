<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Twig\Component;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Twig\Component\ImageComponent;
use PHPUnit\Framework\TestCase;

class ImageComponentTest extends TestCase
{
    private ImageMetadataReader&\PHPUnit\Framework\MockObject\MockObject $metadataReader;

    private function createComponent(bool $globalAutoDimensions = false): ImageComponent
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200, 1920, 2048, 3840], '/_image');
        $blurGenerator = $this->createStub(BlurPlaceholderGenerator::class);
        $this->metadataReader = $this->createMock(ImageMetadataReader::class);

        return new ImageComponent(
            $srcsetGenerator,
            $resolver,
            $blurGenerator,
            $this->metadataReader,
            80,
            ['avif', 'webp'],
            '/_image',
            false,
            $globalAutoDimensions,
            null,
        );
    }

    public function testAutoDimensionsNullFallsBackToGlobalEnabled(): void
    {
        $component = $this->createComponent(globalAutoDimensions: true);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;

        $this->metadataReader->method('calculateHeight')
            ->with('uploads/photo.jpg', 800)
            ->willReturn(600);

        $component->postMount();

        self::assertSame(600, $component->getResolvedHeight());
    }

    public function testAutoDimensionsNullFallsBackToGlobalDisabled(): void
    {
        $component = $this->createComponent(globalAutoDimensions: false);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;

        $this->metadataReader->expects(self::never())->method('calculateHeight');

        $component->postMount();

        self::assertNull($component->getResolvedHeight());
    }

    public function testAutoDimensionsTrueOverridesGlobalDisabled(): void
    {
        $component = $this->createComponent(globalAutoDimensions: false);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->autoDimensions = true;

        $this->metadataReader->method('calculateHeight')
            ->with('uploads/photo.jpg', 800)
            ->willReturn(600);

        $component->postMount();

        self::assertSame(600, $component->getResolvedHeight());
    }

    public function testAutoDimensionsFalseOverridesGlobalEnabled(): void
    {
        $component = $this->createComponent(globalAutoDimensions: true);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->autoDimensions = false;

        $this->metadataReader->expects(self::never())->method('calculateHeight');

        $component->postMount();

        self::assertNull($component->getResolvedHeight());
    }

    public function testExplicitHeightSkipsAutoDimensions(): void
    {
        $component = $this->createComponent(globalAutoDimensions: true);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->height = 400;
        $component->autoDimensions = true;

        $this->metadataReader->expects(self::never())->method('calculateHeight');

        $component->postMount();

        self::assertSame(400, $component->getResolvedHeight());
    }

    public function testSvgSkipsAutoDimensions(): void
    {
        $component = $this->createComponent(globalAutoDimensions: true);
        $component->src = 'icons/logo.svg';
        $component->width = 120;
        $component->autoDimensions = true;

        $this->metadataReader->expects(self::never())->method('calculateHeight');

        $component->postMount();

        self::assertNull($component->getResolvedHeight());
    }

    public function testSvgSrcUsesRoutePrefix(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640], '/_image');

        $component = new ImageComponent(
            $srcsetGenerator,
            $resolver,
            $this->createStub(BlurPlaceholderGenerator::class),
            $this->createStub(ImageMetadataReader::class),
            80,
            ['avif', 'webp'],
            '/_image',
            false,
            false,
            null,
        );
        $component->src = 'icons/logo.svg';
        $component->width = 120;

        self::assertSame('/_image/icons/logo.svg', $component->getSvgSrc());
    }
}
