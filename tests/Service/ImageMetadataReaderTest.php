<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\ImageMetadataReader;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ImageMetadataReaderTest extends TestCase
{
    private ImageMetadataReader $reader;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/id_sign_image_meta_test_'.uniqid();
        mkdir($this->cacheDir, 0o775, true);

        $this->reader = new ImageMetadataReader(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new SourceSizeValidator(0),
            $this->cacheDir,
            0o660,
            0o770,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    /**
     * @return iterable<string, array{int, ?int}>
     */
    public static function fittingBoxProvider(): iterable
    {
        yield 'wider box, unknown height' => [200, null];
        yield 'wider and taller box' => [200, 100];
        yield 'box equal to source' => [100, 75];
    }

    #[DataProvider('fittingBoxProvider')]
    public function testScaleDownReturnsSourceDimensionsWhenSourceFitsBox(int $width, ?int $height): void
    {
        self::assertSame(
            ['width' => 100, 'height' => 75],
            $this->reader->resolveScaleDownDimensions('test.jpg', $width, $height),
        );
    }

    /**
     * @return iterable<string, array{int, ?int}>
     */
    public static function nonFittingBoxProvider(): iterable
    {
        yield 'box lower than source' => [200, 50];
        yield 'box narrower than source' => [80, null];
    }

    #[DataProvider('nonFittingBoxProvider')]
    public function testScaleDownReturnsNullWhenSourceDoesNotFitBox(int $width, ?int $height): void
    {
        self::assertNull($this->reader->resolveScaleDownDimensions('test.jpg', $width, $height));
    }

    public function testDimensionsFollowExifOrientation(): void
    {
        self::assertSame(['width' => 30, 'height' => 40], $this->reader->getDimensions('rotated.jpg'));
    }

    public function testDimensionsFollowExifOrientationOfWebp(): void
    {
        self::assertSame(['width' => 30, 'height' => 40], $this->reader->getDimensions('rotated.webp'));
    }

    public function testDimensionsOfWebpWithoutExif(): void
    {
        self::assertSame(['width' => 40, 'height' => 30], $this->reader->getDimensions('plain.webp'));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
