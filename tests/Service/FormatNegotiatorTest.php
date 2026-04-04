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

    public function testGifFallsBackToJpeg(): void
    {
        self::assertSame('jpeg', FormatNegotiator::getFallbackFormat('gif'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fallbackFormatProvider')]
    public function testGetFallbackFormat(string $extension, string $expected): void
    {
        self::assertSame($expected, FormatNegotiator::getFallbackFormat($extension));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fallbackFormatProvider(): iterable
    {
        yield 'jpg' => ['jpg', 'jpeg'];
        yield 'jpeg' => ['jpeg', 'jpeg'];
        yield 'png' => ['png', 'png'];
        yield 'gif' => ['gif', 'jpeg'];
        yield 'tiff' => ['tiff', 'jpeg'];
        yield 'tif' => ['tif', 'jpeg'];
        yield 'heic' => ['heic', 'jpeg'];
        yield 'heif' => ['heif', 'jpeg'];
        yield 'bmp' => ['bmp', 'jpeg'];
        yield 'avif' => ['avif', 'avif'];
        yield 'webp' => ['webp', 'webp'];
        yield 'unknown' => ['xyz', 'jpeg'];
        yield 'uppercase' => ['JPG', 'jpeg'];
    }

    public function testGetMimeType(): void
    {
        self::assertSame('image/avif', FormatNegotiator::getMimeType('avif'));
        self::assertSame('image/webp', FormatNegotiator::getMimeType('webp'));
        self::assertSame('image/jpeg', FormatNegotiator::getMimeType('jpeg'));
        self::assertSame('image/png', FormatNegotiator::getMimeType('png'));
    }
}
