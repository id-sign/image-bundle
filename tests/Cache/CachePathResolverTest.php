<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Cache;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\TestCase;

class CachePathResolverTest extends TestCase
{
    private CachePathResolver $resolver;
    private UrlSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new UrlSigner('test-secret');
        $this->resolver = new CachePathResolver($this->signer);
    }

    public function testResolveProducesDeterministicPath(): void
    {
        $path1 = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');
        $path2 = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertSame($path1, $path2);
    }

    public function testResolveStartsWithSource(): void
    {
        $path = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertStringStartsWith('uploads/photo.jpg/', $path);
    }

    public function testResolveContainsSignature(): void
    {
        $path = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');
        $signature = $this->signer->sign('uploads/photo.jpg', 800, 600, 'cover', 80);

        self::assertStringContainsString($signature.'_', $path);
    }

    public function testResolveContainsParameters(): void
    {
        $path = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertStringEndsWith('_800_600_cover_80.avif', $path);
    }

    public function testResolveNullHeightUsesAuto(): void
    {
        $path = $this->resolver->resolve('photo.jpg', 800, null, null, 80, 'webp');

        self::assertStringEndsWith('_800_auto_none_80.webp', $path);
    }

    public function testParseValidPath(): void
    {
        $path = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertSame('uploads/photo.jpg', $parsed['src']);
        self::assertSame(800, $parsed['width']);
        self::assertSame(600, $parsed['height']);
        self::assertSame('cover', $parsed['fit']);
        self::assertSame(80, $parsed['quality']);
        self::assertSame('avif', $parsed['format']);
    }

    public function testParseNullHeightAndFit(): void
    {
        $path = $this->resolver->resolve('photo.jpg', 640, null, null, 75, 'webp');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertNull($parsed['height']);
        self::assertNull($parsed['fit']);
    }

    public function testParseInvalidPathReturnsNull(): void
    {
        self::assertNull($this->resolver->parse('invalid'));
        self::assertNull($this->resolver->parse(''));
    }

    public function testParseRejectsUnknownFormat(): void
    {
        // Regex restricts format to the supported set — an unsupported extension must
        // be rejected at parse time, not forwarded to the processor (which would 500).
        $validPath = $this->resolver->resolve('photo.jpg', 800, null, null, 80, 'webp');
        $tampered = preg_replace('/\.webp$/', '.xyz', $validPath);
        self::assertIsString($tampered);

        self::assertNull($this->resolver->parse($tampered));
    }

    public function testParseRejectsUppercaseFormat(): void
    {
        // Bundle always emits lowercase format extensions; uppercase is treated as tampering.
        $validPath = $this->resolver->resolve('photo.jpg', 800, null, null, 80, 'webp');
        $tampered = preg_replace('/\.webp$/', '.WEBP', $validPath);
        self::assertIsString($tampered);

        self::assertNull($this->resolver->parse($tampered));
    }

    public function testParseRoundtripsWithVerify(): void
    {
        $path = $this->resolver->resolve('deep/nested/photo.jpg', 1080, 720, 'contain', 90, 'avif');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertTrue($this->signer->verify(
            $parsed['signature'],
            $parsed['src'],
            $parsed['width'],
            $parsed['height'],
            $parsed['fit'],
            $parsed['quality'],
        ));
    }

    public function testResolveWithWatermarkProfileAddsWmSuffix(): void
    {
        $pathWithout = $this->resolver->resolve('photo.jpg', 800, 600, null, 80, 'avif');
        $pathWith = $this->resolver->resolve('photo.jpg', 800, 600, null, 80, 'avif', 'copyright');

        self::assertStringNotContainsString('_wm', $pathWithout);
        self::assertStringContainsString('_wm-copyright.avif', $pathWith);
        self::assertNotSame($pathWithout, $pathWith);
    }

    public function testParseWatermarkProfile(): void
    {
        $path = $this->resolver->resolve('photo.jpg', 800, null, null, 80, 'webp', 'logo');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertSame('logo', $parsed['watermark']);
    }

    public function testParseNoWatermark(): void
    {
        $path = $this->resolver->resolve('photo.jpg', 800, null, null, 80, 'webp');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertNull($parsed['watermark']);
    }

    public function testWatermarkRoundtripsWithVerify(): void
    {
        $path = $this->resolver->resolve('photo.jpg', 800, 600, 'cover', 80, 'avif', 'brand');
        $parsed = $this->resolver->parse($path);

        self::assertNotNull($parsed);
        self::assertTrue($this->signer->verify(
            $parsed['signature'],
            $parsed['src'],
            $parsed['width'],
            $parsed['height'],
            $parsed['fit'],
            $parsed['quality'],
            $parsed['watermark'],
        ));
    }

    public function testDeleteBySourcePrefix(): void
    {
        $path1 = $this->resolver->resolve('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');
        $path2 = $this->resolver->resolve('uploads/photo.jpg', 640, 480, null, 75, 'webp');
        $path3 = $this->resolver->resolve('uploads/other.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertStringStartsWith('uploads/photo.jpg/', $path1);
        self::assertStringStartsWith('uploads/photo.jpg/', $path2);
        self::assertStringStartsWith('uploads/other.jpg/', $path3);
    }
}
