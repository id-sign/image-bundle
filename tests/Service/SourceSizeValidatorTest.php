<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\SourceTooLargeException;
use PHPUnit\Framework\TestCase;

class SourceSizeValidatorTest extends TestCase
{
    // 100x75 = 7 500 pixels, a few kB
    private const FIXTURE = __DIR__.'/../Fixtures/test.jpg';

    // Two frames: 60x40 red, then 1x1 blue — 2 401 pixels in total. pingImage() stops on the 1x1 frame.
    private const FRAMES = __DIR__.'/../Fixtures/frames.gif';

    public function testZeroDisablesBothLimits(): void
    {
        $validator = new SourceSizeValidator(0, 0);

        $validator->assertFits(self::FIXTURE);
        $validator->assertPixelsFit(100_000, 100_000, self::FIXTURE);

        $this->expectNotToPerformAssertions();
    }

    public function testFileAboveByteLimitIsRejected(): void
    {
        $this->expectException(SourceTooLargeException::class);
        $this->expectExceptionMessage('max_source_bytes');

        (new SourceSizeValidator(16))->assertFits(self::FIXTURE);
    }

    public function testPixelCountAboveLimitIsRejected(): void
    {
        $this->expectException(SourceTooLargeException::class);
        $this->expectExceptionMessage('max_source_pixels: 100x75 = 7500 > 7499');

        (new SourceSizeValidator(0, 7_499))->assertPixelsFit(100, 75, self::FIXTURE);
    }

    public function testPixelCountAtLimitIsAccepted(): void
    {
        (new SourceSizeValidator(0, 7_500))->assertPixelsFit(100, 75, self::FIXTURE);

        $this->expectNotToPerformAssertions();
    }

    /**
     * The per-frame check let a GIF with a tiny last frame through while readImage() decoded every frame
     * (25 kB of GIF → 500 MB). All frames count, and the message says so.
     */
    public function testPingedImageIsCheckedAcrossAllFrames(): void
    {
        $pinged = $this->ping(self::FRAMES);

        $this->expectException(SourceTooLargeException::class);
        $this->expectExceptionMessage('max_source_pixels: 2 frames, 2401 pixels in total > 2400');

        (new SourceSizeValidator(0, 2_400))->assertPingedFits($pinged, self::FRAMES);
    }

    public function testPingedImageWithinTheLimitLeavesTheIteratorOnTheFirstFrame(): void
    {
        $pinged = $this->ping(self::FRAMES);
        self::assertSame(1, $pinged->getImageWidth(), 'pingImage() leaves the iterator on the last frame');

        (new SourceSizeValidator(0, 2_401))->assertPingedFits($pinged, self::FRAMES);

        self::assertSame([60, 40], [$pinged->getImageWidth(), $pinged->getImageHeight()]);
    }

    public function testSingleFramePingedImageKeepsTheDimensionsInTheMessage(): void
    {
        $this->expectException(SourceTooLargeException::class);
        $this->expectExceptionMessage('max_source_pixels: 100x75 = 7500 > 7499');

        (new SourceSizeValidator(0, 7_499))->assertPingedFits($this->ping(self::FIXTURE), self::FIXTURE);
    }

    public function testZeroPixelLimitSkipsThePingedCheckButResetsTheIterator(): void
    {
        $pinged = $this->ping(self::FRAMES);

        (new SourceSizeValidator(0, 0))->assertPingedFits($pinged, self::FRAMES);

        self::assertSame(60, $pinged->getImageWidth());
    }

    public function testSourceTooLargeIsARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        (new SourceSizeValidator(0, 1))->assertPixelsFit(2, 2, self::FIXTURE);
    }

    private function ping(string $path): \Imagick
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('ext-imagick is not installed.');
        }

        $imagick = new \Imagick();
        $imagick->pingImage($path);

        return $imagick;
    }
}
