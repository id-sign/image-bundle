<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\FormatNegotiator;
use PHPUnit\Framework\TestCase;

class FormatNegotiatorTest extends TestCase
{
    private FormatNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new FormatNegotiator(['avif', 'webp']);
    }

    public function testNegotiateAvifWhenSupported(): void
    {
        $format = $this->negotiator->negotiate('image/avif,image/webp,image/jpeg', 'jpg');

        self::assertSame('avif', $format);
    }

    public function testNegotiateWebpWhenAvifNotSupported(): void
    {
        $format = $this->negotiator->negotiate('image/webp,image/jpeg', 'jpg');

        self::assertSame('webp', $format);
    }

    public function testFallbackToSourceFormat(): void
    {
        $format = $this->negotiator->negotiate('image/jpeg', 'jpg');

        self::assertSame('jpeg', $format);
    }

    public function testFallbackTiffToJpeg(): void
    {
        $format = $this->negotiator->negotiate('image/jpeg', 'tiff');

        self::assertSame('jpeg', $format);
    }

    public function testFallbackHeicToJpeg(): void
    {
        $format = $this->negotiator->negotiate('', 'heic');

        self::assertSame('jpeg', $format);
    }

    public function testUnknownExtensionFallsBackToJpeg(): void
    {
        $format = $this->negotiator->negotiate('', 'xyz');

        self::assertSame('jpeg', $format);
    }

    public function testPngPreserved(): void
    {
        $format = $this->negotiator->negotiate('', 'png');

        self::assertSame('png', $format);
    }

    public function testGetMimeType(): void
    {
        self::assertSame('image/avif', FormatNegotiator::getMimeType('avif'));
        self::assertSame('image/webp', FormatNegotiator::getMimeType('webp'));
        self::assertSame('image/jpeg', FormatNegotiator::getMimeType('jpeg'));
        self::assertSame('image/png', FormatNegotiator::getMimeType('png'));
    }
}
