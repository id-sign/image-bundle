<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\ExifOrientation;
use IdSign\ImageBundle\Service\ImagickProcessor;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\SourceTooLargeException;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImagickProcessorTest extends TestCase
{
    // 512x512 random noise. The fixture MUST stay high-entropy: noise is near-incompressible,
    // so quality has a large effect (~2x size between q20 and q90) that holds across libwebp /
    // libheif versions. A smooth or low-detail image can compress to the same size at q20 and
    // q90 on some encoder builds, making the quality-sensitivity assertions flaky.
    private const FIXTURE = __DIR__.'/../Fixtures/detailed.jpg';

    private ImagickProcessor $processor;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('ext-imagick is not installed.');
        }

        $this->processor = new ImagickProcessor(new SourceSizeValidator(0));
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->tmpFiles = [];
    }

    /**
     * AVIF must honor the quality argument. Before the fix only the per-image quality setter
     * was used, which the HEIC/AVIF delegate ignores — every quality produced a byte-identical
     * file at the encoder default. This test fails against that regression.
     */
    public function testAvifRespectsQuality(): void
    {
        $this->requireAvifEncoder();

        $low = $this->renderSize('avif', 20);
        $high = $this->renderSize('avif', 90);

        self::assertLessThan(
            $high,
            $low,
            'AVIF at quality=20 must produce a smaller file than quality=90.',
        );
    }

    /**
     * Regression guard: the AVIF quality fix added a second quality setter; WebP must keep
     * responding to quality.
     *
     * Note the wide quality spread (10 vs 100). libwebp on ImageMagick 6.9.12 (Ubuntu 24.04, our
     * CI) has a near-flat quality curve for lossy WebP — q1..q95 encode to an identical size and
     * only q100 breaks away. A narrower pair (e.g. 20 vs 90) lands on the same plateau and is
     * byte-identical there, so the assertion must straddle the q100 cliff to hold across builds.
     */
    public function testWebpRespectsQuality(): void
    {
        $low = $this->renderSize('webp', 10);
        $high = $this->renderSize('webp', 100);

        self::assertLessThan(
            $high,
            $low,
            'WebP at quality=10 must produce a smaller file than quality=100.',
        );
    }

    /**
     * Lossless AVIF must produce a substantially larger file than a lossy variant. On builds
     * with the AV1 lossless encoder this is true lossless; on builds without it the quality=100
     * floor still guarantees a much larger, high-quality file (never the lossy default).
     */
    public function testAvifLosslessIsLargerThanLossy(): void
    {
        $this->requireAvifEncoder();

        $lossy = $this->renderSize('avif', 80, false);
        $lossless = $this->renderSize('avif', 80, true);

        self::assertGreaterThan(
            $lossy,
            $lossless,
            'Lossless AVIF must be larger than lossy AVIF at the same quality.',
        );
    }

    #[DataProvider('wideSources')]
    public function testSrcsetBreakpointDeliversDescriptorWidth(int $sourceWidth, int $sourceHeight): void
    {
        $source = $this->tmpPath('png');
        $image = new \Imagick();
        $image->newImage($sourceWidth, $sourceHeight, 'red');
        $image->setImageFormat('png');
        $image->writeImage($source);
        $image->clear();

        $resolver = new CachePathResolver(new UrlSigner('test-secret'));
        $generator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200], '');
        $entries = $generator->generate('photo.png', $sourceWidth, $sourceHeight, 'scale-down', 80, 'png');

        self::assertCount(5, $entries);

        foreach ($entries as $entry) {
            $params = $resolver->parse(ltrim($entry['url'], '/'));
            self::assertNotNull($params);

            $output = $this->tmpPath('png');
            $this->processor->process($source, $output, $params['width'], $params['height'], $params['fit'], $params['format'], $params['quality']);

            $size = getimagesize($output);
            self::assertNotFalse($size);
            self::assertSame($entry['width'], $size[0]);
        }
    }

    /**
     * Shrink-on-load: a JPEG at least twice the output box is decoded at a DCT fraction of its size.
     * The output must keep the exact dimensions of a full decode, and a rotated source must use the
     * displayed (swapped) size for the proportional height — the same number ImageMetadataReader
     * puts into <img height>.
     *
     * @return iterable<string, array{string, int, int, int}>
     */
    public static function shrinkOnLoadSources(): iterable
    {
        yield '512x512 → 128 (hint 256x256, decoded at 1/2)' => [self::FIXTURE, 128, 128, 128];
        yield '100x75 → 40 (hint 80x60 within the source)' => [__DIR__.'/../Fixtures/test.jpg', 40, 40, 30];
        yield '100x75 → 60 (source smaller than twice the box: no hint)' => [__DIR__.'/../Fixtures/test.jpg', 60, 60, 45];
        yield 'rotated 40x30 EXIF 6 → 10 (displayed 30x40, hint in stored orientation)' => [__DIR__.'/../Fixtures/rotated.jpg', 10, 10, 13];
    }

    #[DataProvider('shrinkOnLoadSources')]
    public function testJpegShrinkOnLoadKeepsOutputDimensions(string $source, int $width, int $expectedWidth, int $expectedHeight): void
    {
        $output = $this->tmpPath('webp');

        $this->processor->process($source, $output, $width, null, null, 'webp', 80);

        $size = getimagesize($output);
        self::assertNotFalse($size);
        self::assertSame([$expectedWidth, $expectedHeight], [$size[0], $size[1]]);
    }

    public function testSourceAbovePixelLimitIsRejected(): void
    {
        // test.jpg is 100x75 = 7 500 pixels
        $processor = new ImagickProcessor(new SourceSizeValidator(0, 1_000));

        $this->expectException(SourceTooLargeException::class);

        $processor->process(__DIR__.'/../Fixtures/test.jpg', $this->tmpPath('webp'), 50, null, null, 'webp', 80);
    }

    /**
     * p3.jpg stores flat rgb(100,199,80) with a Display P3 profile, which is rgb(46,202,57) in sRGB. The variant
     * must carry the sRGB values and no profile; before the fix it kept the stored values (washed-out colours for
     * every iPhone photo), because transformImageColorspace() is a no-op for an RGB-encoded image.
     */
    public function testEmbeddedProfileIsConvertedToSrgbBeforeStripping(): void
    {
        $output = $this->tmpPath('png');

        $this->processor->process(__DIR__.'/../Fixtures/p3.jpg', $output, 32, null, null, 'png', 80);

        $result = new \Imagick($output);
        self::assertEqualsWithDelta([46, 202, 57], $result->exportImagePixels(16, 12, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 3);
        self::assertSame([], $result->getImageProfiles('icc', false));
    }

    public function testSrgbTaggedSourceKeepsItsColours(): void
    {
        $output = $this->tmpPath('png');

        $this->processor->process(__DIR__.'/../Fixtures/srgb-tagged.jpg', $output, 32, null, null, 'png', 80);

        $result = new \Imagick($output);
        self::assertEqualsWithDelta([100, 199, 80], $result->exportImagePixels(16, 12, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 1);
        self::assertSame([], $result->getImageProfiles('icc', false));
    }

    /**
     * The EXIF rotation runs after the fit. For the proportional resize the pixels must match rotating first
     * (a 90° rotation is a lossless permutation and Lanczos is symmetric under it) up to the 8-bit rounding of
     * the PNG round-trip, and the direction must be the displayed one: rotated.jpg stores a red top row and a
     * blue bottom row with Orientation=6, so the displayed image has red on the right and blue on the left.
     */
    public function testRotationAfterResizeMatchesRotationBeforeResize(): void
    {
        $output = $this->tmpPath('png');
        $this->processor->process(__DIR__.'/../Fixtures/rotated.jpg', $output, 20, null, null, 'png', 100);

        $reference = new \Imagick(__DIR__.'/../Fixtures/rotated.jpg');
        ExifOrientation::autoRotate($reference);
        $reference->resizeImage(20, 27, \Imagick::FILTER_LANCZOS, 1);
        $actual = new \Imagick($output);

        try {
            self::assertSame([20, 27], [$actual->getImageWidth(), $actual->getImageHeight()]);

            $expected = $reference->exportImagePixels(0, 0, 20, 27, 'RGB', \Imagick::PIXEL_CHAR);
            $pixels = $actual->exportImagePixels(0, 0, 20, 27, 'RGB', \Imagick::PIXEL_CHAR);
            $maxDiff = max(array_map(static fn (int|float $a, int|float $b): int => (int) abs($a - $b), $expected, $pixels));
            self::assertLessThanOrEqual(1, $maxDiff, 'rotate-after-resize must match rotate-before-resize up to 8-bit rounding');

            $left = $actual->getImagePixelColor(0, 13)->getColor();
            $right = $actual->getImagePixelColor(19, 13)->getColor();
            self::assertGreaterThan(200, $right['r'], 'stored top row (red) must end up on the right');
            self::assertGreaterThan(200, $left['b'], 'stored bottom row (blue) must end up on the left');
        } finally {
            $reference->clear();
            $actual->clear();
        }
    }

    /**
     * @return iterable<string, array{string, ?int, ?string, int, int}>
     */
    public static function rotatedFits(): iterable
    {
        // rotated.jpg: stored 40x30, EXIF 6, displayed 30x40
        yield 'cover 12x12' => ['cover', 12, 'cover', 12, 12];
        yield 'contain 12x12 → 9x12' => ['contain', 12, 'contain', 9, 12];
        yield 'scale-down 1200 keeps the source size' => ['scale-down', null, 'scale-down', 30, 40];
        yield 'scale-down 12x12 → 9x12' => ['scale-down', 12, 'scale-down', 9, 12];
    }

    #[DataProvider('rotatedFits')]
    public function testFitBoxIsSwappedForQuarterTurnSources(string $label, ?int $height, string $fit, int $expectedWidth, int $expectedHeight): void
    {
        $output = $this->tmpPath('png');
        $width = null === $height ? 1200 : 12;

        $this->processor->process(__DIR__.'/../Fixtures/rotated.jpg', $output, $width, $height, $fit, 'png', 100);

        $size = getimagesize($output);
        self::assertNotFalse($size);
        self::assertSame([$expectedWidth, $expectedHeight], [$size[0], $size[1]], $label);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function wideSources(): iterable
    {
        yield '1408x848' => [1408, 848];
        yield '1920x40' => [1920, 40];
    }

    private function renderSize(string $format, int $quality, bool $lossless = false): int
    {
        $output = $this->tmpPath($format);

        $this->processor->process(
            self::FIXTURE,
            $output,
            300,
            null,
            null,
            $format,
            $quality,
            null,
            $lossless,
        );

        $size = filesize($output);
        self::assertNotFalse($size);
        self::assertGreaterThan(0, $size);

        return $size;
    }

    private function tmpPath(string $format): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imgproc_').'.'.$format;
        $this->tmpFiles[] = $path;

        return $path;
    }

    /**
     * AVIF read support does not imply encode support. CI without libheif-plugin-aomenc can
     * read AVIF but not write it — probe with a real encode and skip when it fails.
     */
    private function requireAvifEncoder(): void
    {
        if (!\in_array('AVIF', \Imagick::queryFormats('AVIF'), true)) {
            self::markTestSkipped('Imagick has no AVIF support.');
        }

        try {
            $probe = new \Imagick();
            $probe->newImage(2, 2, 'red');
            $probe->setImageFormat('AVIF');
            $blob = $probe->getImagesBlob();
            $probe->clear();

            if ('' === $blob) {
                self::markTestSkipped('AVIF encoder produced no output (no AV1 encoder available).');
            }
        } catch (\ImagickException $e) {
            self::markTestSkipped('AVIF encoder unavailable: '.$e->getMessage());
        }
    }
}
