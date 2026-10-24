<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Brings an image to sRGB before its metadata is stripped.
 *
 * stripImage() drops the embedded ICC profile. Without a conversion the pixel values of a Display P3 (every
 * iPhone photo), Adobe RGB, monitor-profiled screenshot or CMYK press-ready source would then be read as sRGB —
 * visibly washed-out or shifted colours. transformImageColorspace(sRGB) does not do that conversion: ImageMagick
 * already reports such images as sRGB because their *encoding* is RGB, so the call is a no-op. Only
 * profileImage() converts the pixels through the embedded profile (via LCMS).
 */
final class ColorProfile
{
    /**
     * sRGB2014.icc, the ICC's v2 sRGB profile (Copyright International Color Consortium, 2015; "may be copied,
     * distributed, embedded, made, used, and sold without restriction"). Measured against the sRGB profiles found
     * in real-world files (HP/Microsoft "sRGB IEC61966-2.1", Apple's and Google's compact "sRGB" and "c2", GIMP
     * built-in sRGB) a conversion to it changes at most 1/255 on a fraction of the values — the same image; the
     * deprecated "no black scaling" variant would lift dark tones by up to 30/255.
     */
    public const SRGB_PROFILE = __DIR__.'/../../resources/sRGB2014.icc';

    /**
     * AdobeCompat-v2.icc from Compact-ICC-Profiles (CC0): the Adobe RGB (1998) colour space without Adobe's
     * licence terms; converts identically to Adobe's own profile (0 of 90 000 values differ on a test image).
     */
    public const ADOBE_RGB_PROFILE = __DIR__.'/../../resources/AdobeCompat-v2.icc';

    private const PROBE_SIZE = 16;

    /**
     * Tags a camera JPEG shot in Adobe RGB mode that carries no ICC profile, so that normalizeToSrgb() converts it.
     * DCF cameras write EXIF InteroperabilityIndex "R03" instead of a profile (see ExifInteroperability); browsers
     * ignore it and show such photos washed out, so the conversion is an improvement over the original. Call it
     * right after readImage(): thumbnailImage() and cropThumbnailImage() drop the EXIF block (they keep ICC), so
     * afterwards there is nothing to read. Images with a profile or without the tag are left alone; a few µs.
     *
     * @throws \ImagickException
     * @throws \RuntimeException when the bundled profile cannot be read
     */
    public static function tagFromExif(\Imagick $imagick): void
    {
        if ([] !== $imagick->getImageProfiles('icc', false)) {
            return;
        }

        $exif = $imagick->getImageProfiles('exif', true)['exif'] ?? null;

        if (!\is_string($exif) || 'R03' !== ExifInteroperability::index($exif)) {
            return;
        }

        $imagick->setImageProfile('icc', self::read(self::ADOBE_RGB_PROFILE));
    }

    /**
     * Converts the pixels to sRGB, honouring an embedded ICC profile, and leaves the image tagged with the bundled
     * sRGB profile for the caller to strip. About 80 ns per pixel (40 ms for 800×600), so callers run it after the
     * fit, on the small output — and only on cache miss. A profile that is itself sRGB (see isSrgb()) is left alone,
     * which costs 2–4 ms instead; an image without a profile is assumed to be sRGB already.
     *
     * A profile that does not fit the image (an RGB profile on CMYK pixels — ImageMagick 7 would silently convert
     * the CMYK channels as if they were RGB, 6 throws; anything that is not an RGB, CMYK or GRAY profile) or that
     * LCMS cannot open (truncated) is reported to the logger and stripped without conversion, as every version
     * before did — a wrong-coloured variant is better than none, but it is cached, so the warning is the only trace.
     *
     * @throws \ImagickException
     * @throws \RuntimeException when the bundled sRGB profile cannot be read (IdSignImageExtension checks it at
     *                           container build time, so this does not happen in a Symfony application)
     */
    public static function normalizeToSrgb(\Imagick $imagick, ?LoggerInterface $logger = null): void
    {
        $profile = $imagick->getImageProfiles('icc', true)['icc'] ?? null;

        if (\is_string($profile)) {
            $profileSpace = self::profileColorspace($profile);

            if (!self::profileFitsImage($profileSpace, $imagick)) {
                $logger?->warning('The ICC profile of an image ("{profile}") does not match the image\'s colorspace; the profile is stripped without conversion.', [
                    'profile' => $profileSpace,
                ]);
            } else {
                try {
                    // The sRGB probe only makes sense for RGB profiles; CMYK and GRAY profiles always convert.
                    if ('RGB ' !== $profileSpace || !self::isSrgb($profile)) {
                        // Converts the pixels and leaves the image in sRGB — nothing more to do.
                        $imagick->profileImage('icc', self::srgbProfile());

                        return;
                    }
                } catch (\ImagickException $e) {
                    $logger?->warning('The ICC profile of an image could not be converted to sRGB; the profile is stripped without conversion: {message}', [
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }
        }

        // No profile, an sRGB-tagged image, or the fallback above: the plain transform turns profile-less CMYK and
        // grayscale into sRGB and is a no-op for RGB-encoded images.
        $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
    }

    /**
     * Whether a conversion through this profile to sRGB would change nothing, i.e. the profile is one of the many
     * byte-variants of sRGB in the wild (HP "sRGB IEC61966-2.1" in camera and Photoshop JPEGs, Apple's and
     * Google's compact profiles, GIMP's built-in sRGB with its per-file metadata, …). A hash list cannot cover
     * them — 22 distinct sRGB blobs among 47 RGB profiles in one sample — so the question is asked empirically:
     * a 16×16 pattern covering the RGB cube and a dark-to-light gray ramp is converted, and ≤1/255 everywhere means
     * sRGB. Costs 2–4 ms (building the LCMS transform) whatever the image size, against 36 ms–0.5 s for converting
     * an sRGB-tagged 0.5–8 MP image for nothing. Monitor profiles (deltas of 7+) and wide gamuts are not sRGB.
     *
     * @throws \ImagickException when LCMS cannot open the profile
     * @throws \RuntimeException when the bundled sRGB profile cannot be read
     */
    public static function isSrgb(string $profile): bool
    {
        if ($profile === self::srgbProfile()) {
            return true;
        }

        $pattern = self::probePattern();
        $probe = new \Imagick();

        try {
            $probe->newImage(self::PROBE_SIZE, self::PROBE_SIZE, 'black');
            $probe->importImagePixels(0, 0, self::PROBE_SIZE, self::PROBE_SIZE, 'RGB', \Imagick::PIXEL_CHAR, $pattern);
            $probe->setImageProfile('icc', $profile);
            $probe->profileImage('icc', self::srgbProfile());
            $converted = array_map(intval(...), $probe->exportImagePixels(0, 0, self::PROBE_SIZE, self::PROBE_SIZE, 'RGB', \Imagick::PIXEL_CHAR));
        } finally {
            $probe->clear();
        }

        foreach ($pattern as $i => $value) {
            if (abs($value - ($converted[$i] ?? -1)) > 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * 256 RGB triplets: a 6×6×6 cube (216 colours, including the saturated corners that wide gamuts move the most)
     * and a 40-step gray ramp (dark tones, where black-point differences between sRGB variants would show).
     *
     * @return list<int>
     */
    private static function probePattern(): array
    {
        $pattern = [];
        $levels = [0, 51, 102, 153, 204, 255];

        foreach ($levels as $r) {
            foreach ($levels as $g) {
                foreach ($levels as $b) {
                    array_push($pattern, $r, $g, $b);
                }
            }
        }

        for ($i = 0; $i < self::PROBE_SIZE * self::PROBE_SIZE - 216; ++$i) {
            $gray = (int) round($i * 255 / 39);
            array_push($pattern, $gray, $gray, $gray);
        }

        return $pattern;
    }

    /**
     * The data colour space from the ICC header (bytes 16–19): "RGB ", "CMYK", "GRAY", "Lab ", …; "" for anything
     * too short to be a profile.
     */
    private static function profileColorspace(string $profile): string
    {
        return \strlen($profile) >= 128 ? substr($profile, 16, 4) : '';
    }

    /**
     * ImageMagick does not check this itself: an RGB profile on CMYK pixels is "converted" channel by channel on 7
     * and rejected on 6. An RGB profile on a grayscale image is fine (the gray values are taken as equal RGB).
     */
    private static function profileFitsImage(string $profileSpace, \Imagick $imagick): bool
    {
        $imageSpace = $imagick->getImageColorspace();

        return match ($profileSpace) {
            'RGB ' => \Imagick::COLORSPACE_CMYK !== $imageSpace,
            'CMYK' => \Imagick::COLORSPACE_CMYK === $imageSpace,
            'GRAY' => \Imagick::COLORSPACE_GRAY === $imageSpace,
            default => false,
        };
    }

    /**
     * @throws \RuntimeException
     */
    private static function srgbProfile(): string
    {
        return self::read(self::SRGB_PROFILE);
    }

    /**
     * @throws \RuntimeException
     */
    private static function read(string $path): string
    {
        $profile = @file_get_contents($path);
        if (false === $profile) {
            throw new \RuntimeException(\sprintf('The bundled ICC profile "%s" is missing or unreadable; install id-sign/image-bundle with its resources/ directory.', $path));
        }

        return $profile;
    }
}
