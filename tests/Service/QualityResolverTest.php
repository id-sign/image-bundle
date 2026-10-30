<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Service\QualityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QualityResolverTest extends TestCase
{
    private QualityResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new QualityResolver(80, ['avif' => 55, 'webp' => 75]);
    }

    public function testConfiguredFormatUsesFormatQuality(): void
    {
        self::assertSame(55, $this->resolver->resolve('avif'));
        self::assertSame(75, $this->resolver->resolve('webp'));
    }

    public function testFormatWithoutFormatQualityUsesDefaultQuality(): void
    {
        self::assertSame(80, $this->resolver->resolve('jpeg'));
        self::assertSame(70, (new QualityResolver(70))->resolve('avif'));
    }

    public function testIntOverrideAppliesToEveryFormat(): void
    {
        self::assertSame(90, $this->resolver->resolve('avif', 90));
        self::assertSame(90, $this->resolver->resolve('webp', 90));
        self::assertSame(90, $this->resolver->resolve('jpeg', 90));
    }

    public function testMapOverrideAppliesOnlyToTheFormatsItNames(): void
    {
        $quality = ['webp' => 90];

        self::assertSame(90, $this->resolver->resolve('webp', $quality));
        self::assertSame(55, $this->resolver->resolve('avif', $quality), 'unnamed format keeps format_quality');
        self::assertSame(80, $this->resolver->resolve('jpeg', $quality), 'unnamed format keeps default_quality');
    }

    /**
     * @return iterable<string, array{int|array<mixed>|null}>
     */
    public static function validQualities(): iterable
    {
        yield 'null' => [null];
        yield 'lower bound' => [1];
        yield 'upper bound' => [100];
        yield 'empty map' => [[]];
        yield 'map of every output format' => [['avif' => 1, 'webp' => 50, 'jpeg' => 90, 'png' => 100]];
    }

    /**
     * @param int|array<mixed>|null $quality
     */
    #[DataProvider('validQualities')]
    public function testAssertValidAccepts(int|array|null $quality): void
    {
        $this->expectNotToPerformAssertions();

        $this->resolver->assertValid($quality, 'photo.jpg');
    }

    /**
     * @return iterable<string, array{int|array<mixed>, string}>
     */
    public static function invalidQualities(): iterable
    {
        yield 'zero' => [0, 'got 0'];
        yield 'above 100' => [101, 'got 101'];
        yield 'map value out of range' => [['avif' => 0], 'got 0'];
        yield 'map value as string' => [['avif' => '60'], 'got string'];
        yield 'map value null' => [['avif' => null], 'got null'];
        yield '"jpg" instead of "jpeg"' => [['jpg' => 80], '"jpg"'];
        yield 'list instead of map' => [[60, 80], '"0"'];
    }

    /**
     * @param int|array<mixed> $quality
     */
    #[DataProvider('invalidQualities')]
    public function testAssertValidRejects(int|array $quality, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/"uploads\/photo\.jpg".*'.preg_quote($message, '/').'/');

        $this->resolver->assertValid($quality, 'uploads/photo.jpg');
    }
}
