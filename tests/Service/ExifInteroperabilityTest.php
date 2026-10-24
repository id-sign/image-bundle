<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\ExifInteroperability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExifInteroperabilityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('ext-imagick is not installed.');
        }
    }

    /** adobergb-r03.jpg: EXIF written by exiftool — ColorSpace=Uncalibrated, InteropIndex=R03, no ICC profile. */
    public function testReadsR03FromACameraStyleJpeg(): void
    {
        self::assertSame('R03', ExifInteroperability::index(self::exifOf(__DIR__.'/../Fixtures/adobergb-r03.jpg')));
    }

    /** rotated.jpg has an EXIF block (Orientation) but no Interoperability IFD. */
    public function testExifWithoutInteroperabilityIfdYieldsNull(): void
    {
        self::assertNull(ExifInteroperability::index(self::exifOf(__DIR__.'/../Fixtures/rotated.jpg')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function byteOrders(): iterable
    {
        yield 'little endian (II), with APP1 prefix' => ['II', "Exif\0\0"];
        yield 'big endian (MM), bare TIFF' => ['MM', ''];
    }

    #[DataProvider('byteOrders')]
    public function testReadsBothByteOrders(string $order, string $prefix): void
    {
        self::assertSame('R98', ExifInteroperability::index($prefix.self::minimalExif($order, "R98\0")));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'not TIFF' => ['Exif'."\0\0".'xxxxxxxxxxxxxxxx'];
        yield 'wrong magic' => ['II'.pack('v', 0x2B).pack('V', 8)];
        yield 'IFD offset beyond the block' => ['II'.pack('v', 0x2A).pack('V', 100000)];
        yield 'truncated after IFD0 count' => ['II'.pack('v', 0x2A).pack('V', 8).pack('v', 3)];
    }

    #[DataProvider('garbage')]
    public function testGarbageYieldsNull(string $exif): void
    {
        self::assertNull(ExifInteroperability::index($exif));
    }

    private static function exifOf(string $path): string
    {
        $imagick = new \Imagick($path);
        $exif = $imagick->getImageProfiles('exif', true)['exif'] ?? null;
        $imagick->clear();
        self::assertIsString($exif);

        return $exif;
    }

    /**
     * TIFF header, IFD0 → Exif IFD (0x8769) → Interoperability IFD (0xA005) → InteroperabilityIndex (0x0001, ASCII,
     * four bytes inline). Each IFD: count, one 12-byte entry, next-IFD pointer = 18 bytes; IFD0 at 8, Exif IFD at
     * 26, Interoperability IFD at 44.
     */
    private static function minimalExif(string $order, string $index): string
    {
        $le = 'II' === $order;
        $u16 = static fn (int $v): string => pack($le ? 'v' : 'n', $v);
        $u32 = static fn (int $v): string => pack($le ? 'V' : 'N', $v);
        $ifd = static fn (int $tag, int $type, int $count, string $data): string => $u16(1).$u16($tag).$u16($type).$u32($count).$data.$u32(0);

        return $order.$u16(0x2A).$u32(8)
            .$ifd(0x8769, 4, 1, $u32(26))
            .$ifd(0xA005, 4, 1, $u32(44))
            .$ifd(0x0001, 2, 4, $index);
    }
}
