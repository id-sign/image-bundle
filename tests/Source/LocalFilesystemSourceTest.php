<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Source;

use IdSign\ImageBundle\Source\LocalFilesystemSource;
use PHPUnit\Framework\TestCase;

class LocalFilesystemSourceTest extends TestCase
{
    private LocalFilesystemSource $source;

    protected function setUp(): void
    {
        $this->source = new LocalFilesystemSource(__DIR__.'/../Fixtures');
    }

    public function testExistsReturnsTrueForExistingFile(): void
    {
        self::assertTrue($this->source->exists('test.jpg'));
    }

    public function testExistsReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->source->exists('nonexistent.jpg'));
    }

    public function testGetAbsolutePath(): void
    {
        $expected = __DIR__.'/../Fixtures/test.jpg';

        self::assertSame($expected, $this->source->getAbsolutePath('test.jpg'));
    }
}
