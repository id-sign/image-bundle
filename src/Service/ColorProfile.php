<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

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
     * Converts the pixels to sRGB, honouring an embedded ICC profile, and leaves the image tagged with the bundled
     * sRGB profile for the caller to strip. Images without a profile are assumed to be sRGB already and only get
     * the plain colorspace transform (profile-less CMYK or grayscale). About 80 ns per pixel (40 ms for 800×600),
     * so callers run it after the fit, on the small output — and only on cache miss.
     *
     * @throws \ImagickException
     */
    public static function normalizeToSrgb(\Imagick $imagick): void
    {
        if ([] !== $imagick->getImageProfiles('icc', false)) {
            try {
                $imagick->profileImage('icc', self::srgbProfile());
            } catch (\ImagickException) {
                // A profile LCMS cannot open (truncated, or not matching the image's colorspace): keep the pixels and
                // let the caller strip the profile, as every version before did.
            }
        }

        $imagick->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
    }

    private static function srgbProfile(): string
    {
        $profile = file_get_contents(self::SRGB_PROFILE);
        if (false === $profile) {
            throw new \RuntimeException(\sprintf('Cannot read the bundled sRGB profile "%s".', self::SRGB_PROFILE));
        }

        return $profile;
    }
}
