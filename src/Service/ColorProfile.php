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

    private const PROBE_SIZE = 16;

    /**
     * Converts the pixels to sRGB, honouring an embedded ICC profile, and leaves the image tagged with the bundled
     * sRGB profile for the caller to strip. Images without a profile are assumed to be sRGB already and only get
     * the plain colorspace transform (profile-less CMYK or grayscale). About 80 ns per pixel (40 ms for 800×600),
     * so callers run it after the fit, on the small output — and only on cache miss. A profile that is itself
     * sRGB (see isSrgb()) is left alone, which costs 2–4 ms instead.
     *
     * @throws \ImagickException
     */
    public static function normalizeToSrgb(\Imagick $imagick): void
    {
        $profile = $imagick->getImageProfiles('icc', true)['icc'] ?? null;

        if (\is_string($profile)) {
            try {
                if (!self::isSrgb($profile)) {
                    $imagick->profileImage('icc', self::srgbProfile());
                }
            } catch (\ImagickException) {
                // A profile LCMS cannot open (truncated, or not matching the image's colorspace): keep the pixels and
                // let the caller strip the profile, as every version before did.
            }
        }

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
     * @throws \ImagickException
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

    private static function srgbProfile(): string
    {
        $profile = file_get_contents(self::SRGB_PROFILE);
        if (false === $profile) {
            throw new \RuntimeException(\sprintf('Cannot read the bundled sRGB profile "%s".', self::SRGB_PROFILE));
        }

        return $profile;
    }
}
