<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Source;

use IdSign\ImageBundle\Source\ImageSourceInterface;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Source\NamedImageSource;
use IdSign\ImageBundle\Source\UnknownImageSourceException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

class NamedImageSourceTest extends TestCase
{
    private string $referenceDir;
    private NamedImageSource $source;

    protected function setUp(): void
    {
        $this->referenceDir = sys_get_temp_dir().'/id_sign_image_named_source_'.uniqid();
        mkdir($this->referenceDir.'/icons', 0o775, true);
        copy(__DIR__.'/../Fixtures/test.jpg', $this->referenceDir.'/icons/ref.jpg');

        $this->source = new NamedImageSource(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new ServiceLocator([
                'reference' => fn (): ImageSourceInterface => new LocalFilesystemSource($this->referenceDir),
            ]),
        );
    }

    protected function tearDown(): void
    {
        unlink($this->referenceDir.'/icons/ref.jpg');
        rmdir($this->referenceDir.'/icons');
        rmdir($this->referenceDir);
    }

    public function testPathWithoutPrefixUsesDefaultSource(): void
    {
        self::assertTrue($this->source->exists('test.jpg'));
        self::assertSame(__DIR__.'/../Fixtures/test.jpg', $this->source->getAbsolutePath('test.jpg'));
    }

    public function testPrefixedPathUsesNamedSourceWithoutThePrefix(): void
    {
        self::assertTrue($this->source->exists('@reference/icons/ref.jpg'));
        self::assertSame($this->referenceDir.'/icons/ref.jpg', $this->source->getAbsolutePath('@reference/icons/ref.jpg'));
    }

    public function testSourcesDoNotSeeEachOthersFiles(): void
    {
        self::assertFalse($this->source->exists('icons/ref.jpg'));
        self::assertFalse($this->source->exists('@reference/test.jpg'));
    }

    public function testUnknownSourceDoesNotExist(): void
    {
        self::assertFalse($this->source->exists('@unknown/test.jpg'));
    }

    public function testUnknownSourceThrowsOnAbsolutePath(): void
    {
        $this->expectException(UnknownImageSourceException::class);
        $this->expectExceptionMessage('@unknown/test.jpg');

        $this->source->getAbsolutePath('@unknown/test.jpg');
    }

    public function testUnknownSourceExceptionIsAnInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->source->getAbsolutePath('@unknown/test.jpg');
    }

    public function testPrefixWithoutSlashIsUnknown(): void
    {
        self::assertFalse($this->source->exists('@reference'));

        $this->expectException(UnknownImageSourceException::class);

        $this->source->getAbsolutePath('@reference');
    }

    public function testExplicitDefaultPrefixIsNotAccepted(): void
    {
        // Entry points normalize "@default/" away; the composite must not resolve it, otherwise the
        // same file would get a second URL and cache directory.
        self::assertFalse($this->source->exists('@default/test.jpg'));

        $this->expectException(UnknownImageSourceException::class);
        $this->expectExceptionMessage('SourcePath::normalize()');

        $this->source->getAbsolutePath('@default/test.jpg');
    }

    public function testTraversalInsideNamedSourceIsRejected(): void
    {
        self::assertFalse($this->source->exists('@reference/../Fixtures/test.jpg'));

        $this->expectException(\InvalidArgumentException::class);

        $this->source->getAbsolutePath('@reference/icons/../../test.jpg');
    }

    public function testNamedSourceIsResolvedLazily(): void
    {
        $instantiated = 0;
        $source = new NamedImageSource(
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new ServiceLocator([
                'reference' => function () use (&$instantiated): ImageSourceInterface {
                    ++$instantiated;

                    return new LocalFilesystemSource($this->referenceDir);
                },
            ]),
        );

        $source->exists('test.jpg');
        self::assertSame(0, $instantiated);

        $source->exists('@reference/icons/ref.jpg');
        self::assertSame(1, $instantiated);
    }
}
