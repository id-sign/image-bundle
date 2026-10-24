<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\ColorProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class ColorProfileTest extends TestCase
{
    /** 64x48 flat rgb(100,199,80) stored with a Display P3 profile — rgb(46,202,57) in sRGB. */
    private const P3 = __DIR__.'/../Fixtures/p3.jpg';

    /** Same pixels, tagged with a compact sRGB profile. */
    private const SRGB_TAGGED = __DIR__.'/../Fixtures/srgb-tagged.jpg';

    /** Same pixels, no profile, EXIF InteropIndex=R03 (a DCF camera in Adobe RGB mode) — rgb(0,200,67) in sRGB. */
    private const ADOBE_RGB_R03 = __DIR__.'/../Fixtures/adobergb-r03.jpg';

    protected function setUp(): void
    {
        if (!class_exists(\Imagick::class)) {
            self::markTestSkipped('ext-imagick is not installed.');
        }
    }

    /**
     * Regression: transformImageColorspace(sRGB) alone left these pixels at rgb(100,199,80) — ImageMagick reports
     * an RGB image with an embedded profile as sRGB already, so only profileImage() converts.
     */
    public function testWideGamutProfileIsConvertedToSrgb(): void
    {
        $imagick = new \Imagick(self::P3);

        ColorProfile::normalizeToSrgb($imagick);

        self::assertEqualsWithDelta([46, 202, 57], $imagick->exportImagePixels(32, 24, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 3);
        self::assertSame(\Imagick::COLORSPACE_SRGB, $imagick->getImageColorspace());
    }

    public function testSrgbTaggedSourceKeepsItsValues(): void
    {
        $imagick = new \Imagick(self::SRGB_TAGGED);

        ColorProfile::normalizeToSrgb($imagick);

        self::assertEqualsWithDelta([100, 199, 80], $imagick->exportImagePixels(32, 24, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 1);
    }

    public function testSourceWithoutProfileIsAssumedSrgb(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgb(100,200,80)');
        $imagick->setImageFormat('png');

        ColorProfile::normalizeToSrgb($imagick);

        self::assertSame([100, 200, 80], $imagick->exportImagePixels(2, 2, 1, 1, 'RGB', \Imagick::PIXEL_CHAR));
        self::assertSame([], $imagick->getImageProfiles('icc', false));
    }

    public function testUnreadableProfileFallsBackToThePlainTransformAndLogsAWarning(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgb(100,200,80)');
        $imagick->setImageFormat('png');
        $imagick->setImageProfile('icc', str_repeat('x', 300));
        $logger = $this->recordingLogger();

        ColorProfile::normalizeToSrgb($imagick, $logger);

        self::assertSame([100, 200, 80], $imagick->exportImagePixels(2, 2, 1, 1, 'RGB', \Imagick::PIXEL_CHAR));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertStringContainsString('stripped without conversion', $logger->records[0]['message']);
    }

    /**
     * An RGB profile on CMYK pixels: ImageMagick 7 would silently convert the CMYK channels as if they were RGB
     * (rgb(140,0,159) for this image), 6 throws. The pixels must survive, the image must end up in sRGB (the
     * fallback transform) and the warning must be logged. Without a logger nothing is thrown either.
     */
    public function testProfileNotMatchingTheColorspaceFallsBackAndLogs(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgb(100,200,80)');
        $imagick->setImageFormat('png');
        $imagick->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
        $imagick->setImageProfile('icc', self::embeddedProfile(self::P3));
        $logger = $this->recordingLogger();

        ColorProfile::normalizeToSrgb($imagick, $logger);

        self::assertSame(\Imagick::COLORSPACE_SRGB, $imagick->getImageColorspace());
        self::assertEqualsWithDelta([100, 200, 80], $imagick->exportImagePixels(2, 2, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 2);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
    }

    public function testAlphaSurvivesTheConversion(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgba(100,200,80,0.5)');
        $imagick->setImageFormat('png');
        $imagick->setImageProfile('icc', self::embeddedProfile(self::P3));

        ColorProfile::normalizeToSrgb($imagick);

        $pixel = array_map(intval(...), $imagick->exportImagePixels(2, 2, 1, 1, 'RGBA', \Imagick::PIXEL_CHAR));
        self::assertEqualsWithDelta([45, 203, 56], \array_slice($pixel, 0, 3), 3);
        self::assertEqualsWithDelta(128, $pixel[3], 1);
    }

    /**
     * A grayscale image tagged with an RGB profile (a wide-gamut one here, so the conversion path runs) is a valid
     * file: the gray values are taken as equal RGB, converted, and the image ends up in sRGB without a warning.
     */
    public function testGrayscaleImageWithAnRgbProfileEndsUpInSrgb(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgb(120,120,120)');
        $imagick->setImageFormat('png');
        $imagick->setImageType(\Imagick::IMGTYPE_GRAYSCALE);
        $imagick->setImageProfile('icc', self::embeddedProfile(self::P3));
        $logger = $this->recordingLogger();

        ColorProfile::normalizeToSrgb($imagick, $logger);

        self::assertSame([], $logger->records);

        self::assertSame(\Imagick::COLORSPACE_SRGB, $imagick->getImageColorspace());
        self::assertEqualsWithDelta([120, 120, 120], $imagick->exportImagePixels(2, 2, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 1);
    }

    /**
     * The probe must tell the sRGB byte-variants (left alone, 2–4 ms) from everything else (converted): the compact
     * sRGB profile of srgb-tagged.jpg is sRGB, the Display P3 profile of p3.jpg is not, the bundled profile is.
     */
    public function testIsSrgbTellsSrgbVariantsFromWideGamutProfiles(): void
    {
        self::assertTrue(ColorProfile::isSrgb(self::embeddedProfile(self::SRGB_TAGGED)));
        self::assertFalse(ColorProfile::isSrgb(self::embeddedProfile(self::P3)));

        $bundled = file_get_contents(ColorProfile::SRGB_PROFILE);
        self::assertNotFalse($bundled);
        self::assertTrue(ColorProfile::isSrgb($bundled));
    }

    public function testIsSrgbRejectsAnUnreadableProfile(): void
    {
        $this->expectException(\ImagickException::class);

        ColorProfile::isSrgb(str_repeat('x', 300));
    }

    public function testCameraJpegInAdobeRgbModeIsTaggedFromExifAndConverted(): void
    {
        $imagick = new \Imagick(self::ADOBE_RGB_R03);
        self::assertSame([], $imagick->getImageProfiles('icc', false));

        ColorProfile::tagFromExif($imagick);
        self::assertSame(['icc'], $imagick->getImageProfiles('icc', false));

        ColorProfile::normalizeToSrgb($imagick);
        self::assertEqualsWithDelta([0, 200, 67], $imagick->exportImagePixels(32, 24, 1, 1, 'RGB', \Imagick::PIXEL_CHAR), 3);
    }

    public function testTagFromExifLeavesOtherImagesAlone(): void
    {
        $profiled = new \Imagick(self::P3);
        $before = $profiled->getImageProfiles('icc', true);
        ColorProfile::tagFromExif($profiled);
        self::assertSame($before, $profiled->getImageProfiles('icc', true), 'an existing profile wins over EXIF');

        $noExif = new \Imagick(__DIR__.'/../Fixtures/detailed.jpg');
        ColorProfile::tagFromExif($noExif);
        self::assertSame([], $noExif->getImageProfiles('icc', false));

        $exifWithoutInterop = new \Imagick(__DIR__.'/../Fixtures/rotated.jpg');
        ColorProfile::tagFromExif($exifWithoutInterop);
        self::assertSame([], $exifWithoutInterop->getImageProfiles('icc', false));
    }

    public function testBundledProfileIsTheIccSrgb2014(): void
    {
        $profile = file_get_contents(ColorProfile::SRGB_PROFILE);
        self::assertNotFalse($profile);

        $probe = new \Imagick();
        $probe->newImage(1, 1, 'white');
        $probe->profileImage('icc', $profile);

        self::assertSame('sRGB2014', $probe->getImageProperty('icc:description'));
        self::assertSame('Copyright International Color Consortium, 2015', $probe->getImageProperty('icc:copyright'));
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };
    }

    private static function embeddedProfile(string $path): string
    {
        $imagick = new \Imagick($path);
        $profile = $imagick->getImageProfiles('icc', true)['icc'] ?? null;
        $imagick->clear();
        self::assertIsString($profile);

        return $profile;
    }
}
