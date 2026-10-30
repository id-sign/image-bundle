<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Twig\Component;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\QualityResolver;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Twig\Component\ImageComponent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

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
    private function createComponentWithStubs(QualityResolver $qualityResolver = new QualityResolver(80)): ImageComponent
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $srcsetGenerator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200, 1920, 2048, 3840], '/_image');

        return new ImageComponent(
            $srcsetGenerator,
            $resolver,
            $this->createStub(BlurPlaceholderGenerator::class),
            $this->createStub(ImageMetadataReader::class),
            $qualityResolver,
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
            new QualityResolver(80),
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

    public function testSvgProducesNoPictureSourcesAndNoFallback(): void
    {
        // ux-twig-component evaluates exposed methods eagerly; the SVG template never reads these.
        $component = $this->createComponentWithStubs();
        $component->src = 'icons/logo.svg';
        $component->width = 120;

        $component->postMount();

        self::assertTrue($component->isSvg());
        self::assertSame([], $component->getSources());
        self::assertSame('', $component->getFallbackSrc());
        self::assertFalse($component->getShowBlur());
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
            new QualityResolver(80),
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
            new QualityResolver(80),
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
            new QualityResolver(80),
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
            new ImageMetadataReader(new LocalFilesystemSource(__DIR__.'/../../Fixtures'), new SourceSizeValidator(0), new LocalFilesystemCacheStorage($cacheDir, 3600, null, 0o770)),
            new QualityResolver(80),
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
            new BlurPlaceholderGenerator($source, new SourceSizeValidator(0), new LocalFilesystemCacheStorage($cacheDir, 3600, null, 0o770), 10, 30),
            new ImageMetadataReader($source, new SourceSizeValidator(0), new LocalFilesystemCacheStorage($cacheDir, 3600, null, 0o770)),
            new QualityResolver(80),
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

    public function testDefaultSourcePrefixRendersIdenticalMarkupToNoPrefix(): void
    {
        $plain = $this->createComponentWithStubs();
        $plain->src = 'uploads/photo.jpg';
        $plain->width = 800;
        $plain->postMount();

        $prefixed = $this->createComponentWithStubs();
        $prefixed->src = '@default/uploads/photo.jpg';
        $prefixed->width = 800;
        $prefixed->postMount();

        self::assertSame('uploads/photo.jpg', $prefixed->src);
        self::assertSame($plain->getFallbackSrc(), $prefixed->getFallbackSrc());
        self::assertSame($plain->getSources(), $prefixed->getSources());
    }

    public function testLeadingSlashRendersIdenticalMarkupToNoSlash(): void
    {
        // The source and the cache storage ignore a leading slash, but it would be signed and produce
        // "/_image//uploads/…" — nginx merges the slashes and the signature no longer matches (403).
        $plain = $this->createComponentWithStubs();
        $plain->src = 'uploads/photo.jpg';
        $plain->width = 800;
        $plain->postMount();

        $slashed = $this->createComponentWithStubs();
        $slashed->src = '/uploads/photo.jpg';
        $slashed->width = 800;
        $slashed->postMount();

        self::assertSame('uploads/photo.jpg', $slashed->src);
        self::assertSame($plain->getFallbackSrc(), $slashed->getFallbackSrc());
        self::assertSame($plain->getSources(), $slashed->getSources());
    }

    public function testDefaultSourcePrefixIsStrippedBeforeMetadataLookup(): void
    {
        $component = $this->createComponent(true);
        $component->src = '@default/uploads/photo.jpg';
        $component->width = 800;
        $this->metadataReader->expects($this->once())
            ->method('calculateHeight')
            ->with('uploads/photo.jpg', 800)
            ->willReturn(600);

        $component->postMount();

        self::assertSame(600, $component->getResolvedHeight());
    }

    public function testNamedSourcePrefixIsPartOfEveryUrl(): void
    {
        $component = $this->createComponentWithStubs();
        $component->src = '@reference/uploads/photo.jpg';
        $component->width = 800;
        $component->postMount();

        self::assertStringStartsWith('/_image/%40reference/uploads/photo.jpg/', $component->getFallbackSrc());

        foreach ($component->getSources() as $source) {
            foreach (explode(', ', $source['srcset']) as $candidate) {
                self::assertStringStartsWith('/_image/%40reference/uploads/photo.jpg/', $candidate);
            }
        }
    }

    public function testNamedSourcePrefixProducesDifferentUrlsThanDefault(): void
    {
        $plain = $this->createComponentWithStubs();
        $plain->src = 'uploads/photo.jpg';
        $plain->width = 800;
        $plain->postMount();

        $prefixed = $this->createComponentWithStubs();
        $prefixed->src = '@reference/uploads/photo.jpg';
        $prefixed->width = 800;
        $prefixed->postMount();

        self::assertNotSame($plain->getFallbackSrc(), $prefixed->getFallbackSrc());
    }

    public function testSvgSrcKeepsNamedSourcePrefixAndDropsDefaultPrefix(): void
    {
        $named = $this->createComponentWithStubs();
        $named->src = '@reference/icons/logo.svg';
        $named->width = 120;
        $named->postMount();

        self::assertTrue($named->isSvg());
        self::assertSame('/_image/%40reference/icons/logo.svg', $named->getSvgSrc());

        $default = $this->createComponentWithStubs();
        $default->src = '@default/icons/logo.svg';
        $default->width = 120;
        $default->postMount();

        self::assertSame('/_image/icons/logo.svg', $default->getSvgSrc());
    }

    public function testFormatQualityGivesEachFormatItsOwnQuality(): void
    {
        $component = $this->createComponentWithStubs(new QualityResolver(80, ['avif' => 55, 'webp' => 75]));
        $component->src = 'uploads/photo.jpg';
        $component->width = 1200;
        $component->postMount();

        [$avif, $webp] = $component->getSources();

        self::assertSame(['55'], $this->qualitiesIn($avif['srcset']));
        self::assertSame(['75'], $this->qualitiesIn($webp['srcset']));
        self::assertStringEndsWith('_80.jpeg', $component->getFallbackSrc(), 'a format without format_quality uses default_quality');
    }

    public function testQualityMapOverridesOnlyTheFormatsItNames(): void
    {
        $component = $this->createComponentWithStubs(new QualityResolver(80, ['avif' => 55, 'webp' => 75]));
        $component->src = 'uploads/photo.jpg';
        $component->width = 1200;
        $component->quality = ['webp' => 90];
        $component->postMount();

        [$avif, $webp] = $component->getSources();

        self::assertSame(['55'], $this->qualitiesIn($avif['srcset']));
        self::assertSame(['90'], $this->qualitiesIn($webp['srcset']));
        self::assertStringEndsWith('_80.jpeg', $component->getFallbackSrc());
    }

    public function testQualityIntOverridesEveryFormat(): void
    {
        $component = $this->createComponentWithStubs(new QualityResolver(80, ['avif' => 55, 'webp' => 75]));
        $component->src = 'uploads/photo.jpg';
        $component->width = 1200;
        $component->quality = 90;
        $component->postMount();

        [$avif, $webp] = $component->getSources();

        self::assertSame(['90'], $this->qualitiesIn($avif['srcset']));
        self::assertSame(['90'], $this->qualitiesIn($webp['srcset']));
        self::assertStringEndsWith('_90.jpeg', $component->getFallbackSrc());
    }

    public function testInvalidQualityThrowsOnMountEvenForSvg(): void
    {
        $component = $this->createComponentWithStubs();
        $component->src = 'icons/logo.svg';
        $component->width = 120;
        $component->quality = ['jpg' => 80];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('icons/logo.svg');

        $component->postMount();
    }

    /**
     * HTML syntax passes quality="90" as a string; ux-twig-component writes props through the PropertyAccessor,
     * whose coercive assignment has to turn it into the int branch of int|array|null.
     */
    public function testQualityAttributeStringMountsAsInt(): void
    {
        $component = $this->createComponentWithStubs();

        PropertyAccess::createPropertyAccessor()->setValue($component, 'quality', '90');

        self::assertSame(90, $component->quality);
    }

    /**
     * @return list<string> distinct quality segments of the srcset URLs
     */
    private function qualitiesIn(string $srcset): array
    {
        preg_match_all('/_(\d+)\.(?:avif|webp|jpeg|png) \d+w/', $srcset, $matches);

        return array_values(array_unique($matches[1]));
    }
}
