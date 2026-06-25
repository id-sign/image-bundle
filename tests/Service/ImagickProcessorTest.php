<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\ImagickProcessor;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use PHPUnit\Framework\TestCase;

class ImagickProcessorTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../Fixtures/detailed.jpg';

    private ImagickProcessor $processor;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('ext-imagick is not installed.');
        }

        $this->processor = new ImagickProcessor(new SourceSizeValidator(0), null, 0o770);
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
     * responding to quality exactly as before.
     */
    public function testWebpRespectsQuality(): void
    {
        $low = $this->renderSize('webp', 20);
        $high = $this->renderSize('webp', 90);

        self::assertLessThan(
            $high,
            $low,
            'WebP at quality=20 must produce a smaller file than quality=90.',
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
