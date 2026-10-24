<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use IdSign\ImageBundle\Service\BlurPlaceholderGenerator;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use PHPUnit\Framework\TestCase;

class BlurPlaceholderGeneratorTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/id_sign_image_blur_test_'.uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->cacheDir.'/rotated.jpg/blur.txt');
        @rmdir($this->cacheDir.'/rotated.jpg');
        @rmdir($this->cacheDir);
    }

    public function testPlaceholderFollowsExifOrientation(): void
    {
        $generator = new BlurPlaceholderGenerator(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new SourceSizeValidator(0),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, null, 0o770),
            10,
            30,
        );

        $dataUri = $generator->generate('rotated.jpg');

        $imagick = new \Imagick();
        $imagick->readImageBlob((string) base64_decode(substr($dataUri, \strlen('data:image/jpeg;base64,')), true));

        self::assertSame(10, $imagick->getImageWidth());
        self::assertGreaterThan(10, $imagick->getImageHeight());
    }

    public function testEmptyBlurTxtIsTreatedAsMissAndRewritten(): void
    {
        mkdir($this->cacheDir.'/rotated.jpg', 0o775, true);
        touch($this->cacheDir.'/rotated.jpg/blur.txt');
        $generator = new BlurPlaceholderGenerator(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new SourceSizeValidator(0),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, null, 0o770),
            10,
            30,
        );

        $dataUri = $generator->generate('rotated.jpg');

        self::assertStringStartsWith('data:image/jpeg;base64,', $dataUri);
        self::assertSame($dataUri, file_get_contents($this->cacheDir.'/rotated.jpg/blur.txt'));
    }

    public function testSourceAbovePixelLimitYieldsEmptyPlaceholderWithoutCacheWrite(): void
    {
        $generator = new BlurPlaceholderGenerator(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new SourceSizeValidator(0, 1_000), // rotated.jpg is 40x30 = 1 200 pixels
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, null, 0o770),
            10,
            30,
        );

        self::assertSame('', $generator->generate('rotated.jpg'));
        self::assertDirectoryDoesNotExist($this->cacheDir.'/rotated.jpg');
    }

    public function testMissingSourceYieldsEmptyPlaceholderWithoutCacheWrite(): void
    {
        $generator = new BlurPlaceholderGenerator(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new SourceSizeValidator(0),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, null, 0o770),
            10,
            30,
        );

        self::assertSame('', $generator->generate('missing.jpg'));
        self::assertDirectoryDoesNotExist($this->cacheDir.'/missing.jpg');
    }
}
