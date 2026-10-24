<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

/**
 * Reads the Interoperability IFD of an EXIF block — the only place where a camera JPEG shot in Adobe RGB says so.
 *
 * DCF cameras (Nikon, Canon, Sony, …) set to Adobe RGB embed no ICC profile; they write ColorSpace = Uncalibrated
 * and InteroperabilityIndex = "R03" ("R98" for sRGB). ImageMagick 7.1 exposes that IFD only under the misleading
 * property name "exif:Thumbnail.InteroperabilityIndex" and 6.9 not at all, so the tag is read here from the EXIF
 * profile ImageMagick keeps in memory: TIFF header → IFD0 → Exif IFD (0x8769) → Interoperability IFD (0xA005) → tag 0x0001.
 * Nothing else of the block is touched; a few µs.
 */
final class ExifInteroperability
{
    private const TAG_EXIF_IFD = 0x8769;
    private const TAG_INTEROP_IFD = 0xA005;
    private const TAG_INTEROP_INDEX = 0x0001;
    private const TYPE_ASCII = 2;

    /**
     * @param string $exif the "exif" profile as Imagick returns it (with or without the "Exif\0\0" APP1 prefix)
     *
     * @return string|null "R03", "R98", … — null when the block is not EXIF or has no Interoperability IFD
     */
    public static function index(string $exif): ?string
    {
        $tiff = str_starts_with($exif, "Exif\0\0") ? substr($exif, 6) : $exif;
        $order = substr($tiff, 0, 2);

        if (('II' !== $order && 'MM' !== $order) || \strlen($tiff) < 8) {
            return null;
        }

        $le = 'II' === $order;

        if (0x2A !== self::u16($tiff, 2, $le)) {
            return null;
        }

        $exifIfd = self::entry($tiff, self::u32($tiff, 4, $le), self::TAG_EXIF_IFD, $le);
        $interopIfd = null === $exifIfd ? null : self::entry($tiff, self::u32($exifIfd['data'], 0, $le), self::TAG_INTEROP_IFD, $le);
        $index = null === $interopIfd ? null : self::entry($tiff, self::u32($interopIfd['data'], 0, $le), self::TAG_INTEROP_INDEX, $le);

        if (null === $index || self::TYPE_ASCII !== $index['type']) {
            return null;
        }

        // Up to four bytes are stored inline, longer values at an offset.
        $raw = $index['count'] <= 4
            ? substr($index['data'], 0, $index['count'])
            : substr($tiff, self::u32($index['data'], 0, $le), $index['count']);

        return '' === rtrim($raw, "\0") ? null : rtrim($raw, "\0");
    }

    /**
     * The entry of $tag in the IFD at $offset: type, count and the four value/offset bytes.
     *
     * @return array{type: int, count: int, data: string}|null
     */
    private static function entry(string $tiff, int $offset, int $tag, bool $le): ?array
    {
        if ($offset < 0 || $offset + 2 > \strlen($tiff)) {
            return null;
        }

        $count = self::u16($tiff, $offset, $le);

        for ($i = 0; $i < $count; ++$i) {
            $at = $offset + 2 + 12 * $i;

            if ($at + 12 > \strlen($tiff)) {
                return null;
            }

            if (self::u16($tiff, $at, $le) === $tag) {
                return [
                    'type' => self::u16($tiff, $at + 2, $le),
                    'count' => self::u32($tiff, $at + 4, $le),
                    'data' => substr($tiff, $at + 8, 4),
                ];
            }
        }

        return null;
    }

    private static function u16(string $data, int $offset, bool $le): int
    {
        $value = $offset + 2 <= \strlen($data) ? unpack($le ? 'v' : 'n', $data, $offset) : false;

        return false === $value ? -1 : (int) $value[1];
    }

    private static function u32(string $data, int $offset, bool $le): int
    {
        $value = $offset + 4 <= \strlen($data) ? unpack($le ? 'V' : 'N', $data, $offset) : false;

        return false === $value ? -1 : (int) $value[1];
    }
}
