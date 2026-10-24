<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Source;

use IdSign\ImageBundle\Source\SourcePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourcePathTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNormalize(): iterable
    {
        yield 'default prefix is stripped' => ['@default/uploads/photo.jpg', 'uploads/photo.jpg'];
        yield 'repeated default prefix is stripped completely (idempotent)' => ['@default/@default/photo.jpg', 'photo.jpg'];
        yield 'no prefix is unchanged' => ['uploads/photo.jpg', 'uploads/photo.jpg'];
        yield 'other prefix is unchanged' => ['@reference/photo.jpg', '@reference/photo.jpg'];
        yield 'default without slash is unchanged' => ['@default', '@default'];
        yield 'default as a directory name deeper in the path is unchanged' => ['uploads/@default/photo.jpg', 'uploads/@default/photo.jpg'];
        yield 'prefix is case-sensitive' => ['@Default/photo.jpg', '@Default/photo.jpg'];
    }

    #[DataProvider('provideNormalize')]
    public function testNormalize(string $src, string $expected): void
    {
        self::assertSame($expected, SourcePath::normalize($src));
    }
}
