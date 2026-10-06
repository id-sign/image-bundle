<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Twig\Component;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Twig\Component\ImageComponent;
use PHPUnit\Framework\TestCase;

class ImageComponentTest extends TestCase
{
    private ImageMetadataReader&\PHPUnit\Framework\MockObject\MockObject $metadataReader;

    private function createComponent(bool $globalAutoDimensions = false): ImageComponent
    {
        return $this->buildComponent($globalAutoDimensions, false);
    }

    private function createComponentWithLossless(bool $globalLossless): ImageComponent
    {
        return $this->buildComponent(false, $globalLossless);
    }

    /**
     * Variant with stubbed metadata reader — for tests that exercise URL output and have
     * no opinion about whether calculateHeight() gets called.
     */
    private function createComponentWithStubs(): ImageComponent
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200, 1920, 2048, 3840], '/_image');

        return new ImageComponent(
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
            4096,
            false,
        );
    }

    private function buildComponent(bool $globalAutoDimensions, bool $globalLossless): ImageComponent
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
            4096,
            $globalLossless,
        );
    }

    public function testPostMountThrowsWhenWidthIsZero(): void
    {
        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('uploads/photo.jpg');

        $component->postMount();
    }

    public function testPostMountThrowsWhenWidthIsNegative(): void
    {
        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->width = -1;
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $this->expectException(\InvalidArgumentException::class);

        $component->postMount();
    }

    public function testLosslessNullFallsBackToGlobalEnabled(): void
    {
        $component = $this->createComponentWithLossless(true);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertStringContainsString('_lossless', $component->getFallbackSrc());
    }

    public function testLosslessNullFallsBackToGlobalDisabled(): void
    {
        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertStringNotContainsString('_lossless', $component->getFallbackSrc());
    }

    public function testLosslessTrueOverridesGlobalDisabled(): void
    {
        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->lossless = true;
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertStringContainsString('_lossless', $component->getFallbackSrc());
    }

    public function testLosslessFalseOverridesGlobalEnabled(): void
    {
        $component = $this->createComponentWithLossless(true);
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->lossless = false;
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertStringNotContainsString('_lossless', $component->getFallbackSrc());
    }

    public function testPostMountThrowsWhenWidthExceedsMaxWidth(): void
    {
        $component = $this->createComponent();
        $component->src = 'uploads/photo.jpg';
        $component->width = 10_000; // default createComponent uses maxWidth=4096
        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_width');

        $component->postMount();
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

        $this->metadataReader->expects($this->never())->method('calculateHeight');

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

        $this->metadataReader->expects($this->never())->method('calculateHeight');

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

        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertSame(400, $component->getResolvedHeight());
    }

    public function testSvgSkipsAutoDimensions(): void
    {
        $component = $this->createComponent(globalAutoDimensions: true);
        $component->src = 'icons/logo.svg';
        $component->width = 120;
        $component->autoDimensions = true;

        $this->metadataReader->expects($this->never())->method('calculateHeight');

        $component->postMount();

        self::assertNull($component->getResolvedHeight());
    }

    public function testHeightWithoutFitIsNotUsedForProcessing(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200, 1920, 2048, 3840], '/_image');

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
            4096,
            false,
        );
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->height = 400;

        $component->postMount();

        self::assertSame(400, $component->getResolvedHeight());

        $fallbackSrc = $component->getFallbackSrc();
        self::assertStringNotContainsString('_400_', $fallbackSrc);
    }

    public function testHeightWithFitIsUsedForProcessing(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200, 1920, 2048, 3840], '/_image');

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
            4096,
            false,
        );
        $component->src = 'uploads/photo.jpg';
        $component->width = 800;
        $component->height = 400;
        $component->fit = 'cover';

        $component->postMount();

        self::assertSame(400, $component->getResolvedHeight());

        $fallbackSrc = $component->getFallbackSrc();
        self::assertStringContainsString('_400_', $fallbackSrc);
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
            4096,
            false,
        );
        $component->src = 'icons/logo.svg';
        $component->width = 120;

        self::assertSame('/_image/icons/logo.svg', $component->getSvgSrc());
    }

    public function testSourcesEncodeSpacesInSrcsetUrls(): void
    {
        $component = $this->createComponentWithStubs();
        $component->src = 'blog/images/ChatGPT Image 18. 5. 2026 12_12_30.png';
        $component->width = 1200;
        $component->postMount();

        $sources = $component->getSources();

        self::assertNotSame([], $sources);

        foreach ($sources as $source) {
            // The srcset has the form "URL1 W1w, URL2 W2w" — extract URL parts and verify none contains a literal space.
            foreach (explode(', ', $source['srcset']) as $entry) {
                $url = explode(' ', $entry)[0];
                self::assertStringNotContainsString(' ', $url, 'srcset URL must not contain literal spaces — it would be parsed as the URL/width-descriptor separator');
                self::assertStringContainsString('ChatGPT%20Image%2018.%205.%202026%2012_12_30.png', $url);
            }
        }
    }

    public function testFallbackSrcEncodesSpaces(): void
    {
        $component = $this->createComponentWithStubs();
        $component->src = 'blog/My Photo.jpg';
        $component->width = 800;
        $component->postMount();

        $src = $component->getFallbackSrc();

        self::assertStringNotContainsString(' ', $src);
        self::assertStringContainsString('/_image/blog/My%20Photo.jpg/', $src);
    }

    public function testSvgSrcEncodesSpaces(): void
    {
        $component = $this->createComponentWithStubs();
        $component->src = 'icons/my logo.svg';
        $component->width = 120;
        $component->postMount();

        self::assertSame('/_image/icons/my%20logo.svg', $component->getSvgSrc());
    }

    public function testScaleDownUsesSourceDimensionsWhenSourceFits(): void
    {
        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 1200;
        $component->fit = 'scale-down';

        $this->metadataReader->expects($this->once())->method('resolveScaleDownDimensions')
            ->with('photo.jpg', 1200, null)
            ->willReturn(['width' => 100, 'height' => 75]);

        $component->postMount();

        self::assertSame(100, $component->width);
        self::assertSame(75, $component->getResolvedHeight());
        self::assertStringContainsString('_100_75_scale-down', $component->getFallbackSrc());

        foreach ($component->getSources() as $source) {
            self::assertStringEndsWith(' 100w', $source['srcset']);
            self::assertSame(1, preg_match_all('/ (\d+)w/', $source['srcset'], $matches));
            self::assertSame(['100'], $matches[1]);
            self::assertStringContainsString('_100_75_scale-down', $source['srcset']);
        }
    }

    public function testScaleDownPassesAutoDimensionsHeight(): void
    {
        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 1200;
        $component->fit = 'scale-down';
        $component->autoDimensions = true;

        $this->metadataReader->method('calculateHeight')->willReturn(900);
        $this->metadataReader->expects($this->once())->method('resolveScaleDownDimensions')
            ->with('photo.jpg', 1200, 900)
            ->willReturn(['width' => 100, 'height' => 75]);

        $component->postMount();

        self::assertSame(100, $component->width);
        self::assertSame(75, $component->getResolvedHeight());
    }

    public function testScaleDownKeepsRequestedSizeWhenSourceDoesNotFit(): void
    {
        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 1200;
        $component->fit = 'scale-down';

        $this->metadataReader->expects($this->once())->method('resolveScaleDownDimensions')->willReturn(null);

        $component->postMount();

        self::assertSame(1200, $component->width);
        self::assertNull($component->getResolvedHeight());
        self::assertStringContainsString('_1200_auto_scale-down', $component->getFallbackSrc());

        foreach ($component->getSources() as $source) {
            self::assertStringEndsWith(' 1200w', $source['srcset']);
        }
    }

    public function testScaleDownUsesDisplayedDimensionsOfExifRotatedSource(): void
    {
        $cacheDir = sys_get_temp_dir().'/id_sign_image_component_test_'.uniqid();
        $resolver = new CachePathResolver(new UrlSigner('test-secret'));
        $component = new ImageComponent(
            new SrcsetGenerator($resolver, [640, 1080], '/_image'),
            $resolver,
            $this->createStub(BlurPlaceholderGenerator::class),
            new ImageMetadataReader(new LocalFilesystemSource(__DIR__.'/../../Fixtures'), new SourceSizeValidator(0), $cacheDir, null, 0o770),
            80,
            ['webp'],
            '/_image',
            false,
            false,
            null,
            4096,
            false,
        );
        $component->src = 'rotated.jpg';
        $component->width = 1200;
        $component->fit = 'scale-down';

        try {
            $component->postMount();
        } finally {
            unlink($cacheDir.'/rotated.jpg/meta.json');
            rmdir($cacheDir.'/rotated.jpg');
            rmdir($cacheDir);
        }

        self::assertSame(30, $component->width);
        self::assertSame(40, $component->getResolvedHeight());
        self::assertStringContainsString('_30_40_scale-down', $component->getFallbackSrc());
    }

    public function testMissingSourceRendersWithoutHeightAndBlur(): void
    {
        $cacheDir = sys_get_temp_dir().'/id_sign_image_component_test_'.uniqid();
        mkdir($cacheDir, 0o770, true);
        $source = new LocalFilesystemSource(__DIR__.'/../../Fixtures');
        $resolver = new CachePathResolver(new UrlSigner('test-secret'));
        $component = new ImageComponent(
            new SrcsetGenerator($resolver, [640, 1080], '/_image'),
            $resolver,
            new BlurPlaceholderGenerator($source, new SourceSizeValidator(0), $cacheDir, 10, 30, null, 0o770),
            new ImageMetadataReader($source, new SourceSizeValidator(0), $cacheDir, null, 0o770),
            80,
            ['webp'],
            '/_image',
            false,
            false,
            null,
            4096,
            false,
        );
        $component->src = 'missing.jpg';
        $component->width = 800;
        $component->autoDimensions = true;
        $component->blur = true;
        $component->fit = 'scale-down';

        try {
            $component->postMount();
            $blurDataUri = $component->getBlurDataUri();
            $cacheEntries = array_diff((array) scandir($cacheDir), ['.', '..']);
        } finally {
            rmdir($cacheDir);
        }

        self::assertSame(800, $component->width);
        self::assertNull($component->getResolvedHeight());
        self::assertSame('', $blurDataUri);
        self::assertSame([], $cacheEntries);
    }

    public function testOtherFitDoesNotResolveScaleDownDimensions(): void
    {
        $component = $this->createComponent();
        $component->src = 'photo.jpg';
        $component->width = 1200;
        $component->fit = 'cover';

        $this->metadataReader->expects($this->never())->method('resolveScaleDownDimensions');

        $component->postMount();

        self::assertSame(1200, $component->width);
    }

    public function testSvgDoesNotResolveScaleDownDimensions(): void
    {
        $component = $this->createComponent();
        $component->src = 'icons/logo.svg';
        $component->width = 120;
        $component->fit = 'scale-down';

        $this->metadataReader->expects($this->never())->method('resolveScaleDownDimensions');

        $component->postMount();

        self::assertSame(120, $component->width);
    }
}
