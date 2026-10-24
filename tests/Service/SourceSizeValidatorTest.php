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

    public function testSourceTooLargeIsARuntimeException(): void
    {
        $this->expectException(\RuntimeException::class);

        (new SourceSizeValidator(0, 1))->assertPixelsFit(2, 2, self::FIXTURE);
    }
}
