<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\ColorProfile;
use PHPUnit\Framework\TestCase;

class ColorProfileTest extends TestCase
{
    /** 64x48 flat rgb(100,199,80) stored with a Display P3 profile — rgb(46,202,57) in sRGB. */
    private const P3 = __DIR__.'/../Fixtures/p3.jpg';

    /** Same pixels, tagged with a compact sRGB profile. */
    private const SRGB_TAGGED = __DIR__.'/../Fixtures/srgb-tagged.jpg';

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

    public function testUnreadableProfileFallsBackToThePlainTransform(): void
    {
        $imagick = new \Imagick();
        $imagick->newImage(4, 4, 'rgb(100,200,80)');
        $imagick->setImageFormat('png');
        $imagick->setImageProfile('icc', str_repeat('x', 300));

        ColorProfile::normalizeToSrgb($imagick);

        self::assertSame([100, 200, 80], $imagick->exportImagePixels(2, 2, 1, 1, 'RGB', \Imagick::PIXEL_CHAR));
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
}
