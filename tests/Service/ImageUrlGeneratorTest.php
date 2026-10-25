<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Service\ImageUrlGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ImageUrlGeneratorTest extends TestCase
{
    private ImageUrlGenerator $generator;

    protected function setUp(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $negotiator = new FormatNegotiator(['avif', 'webp']);

        $this->generator = new ImageUrlGenerator($resolver, $negotiator, '/_image');
    }

    public function testGenerateReturnsUrlWithPrefix(): void
    {
        $url = $this->generator->generate('uploads/photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertStringStartsWith('/_image/uploads/photo.jpg/', $url);
        self::assertStringEndsWith('.avif', $url);
    }

    public function testGenerateWithWatermark(): void
    {
        $url = $this->generator->generate('photo.jpg', 800, 600, null, 80, 'webp', 'copyright');

        self::assertStringContainsString('_wm-copyright', $url);
    }

    public function testGenerateFromRequestNegotiatesAvif(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/avif,image/webp,image/jpeg']);

        $url = $this->generator->generateFromRequest($request, 'photo.jpg', 800);

        self::assertStringEndsWith('.avif', $url);
    }

    public function testGenerateFromRequestFallsBackToWebp(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/webp,image/jpeg']);

        $url = $this->generator->generateFromRequest($request, 'photo.jpg', 800);

        self::assertStringEndsWith('.webp', $url);
    }

    public function testGenerateFromRequestFallsBackToOriginalFormat(): void
    {
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_ACCEPT' => 'image/jpeg']);

        $url = $this->generator->generateFromRequest($request, 'photo.jpg', 800);

        self::assertStringEndsWith('.jpeg', $url);
    }

    public function testGenerateEncodesSrcWithSpaces(): void
    {
        $url = $this->generator->generate('blog/ChatGPT Image.png', 800, null, null, 80, 'avif');

        self::assertStringNotContainsString(' ', $url);
        self::assertStringContainsString('/_image/blog/ChatGPT%20Image.png/', $url);
    }

    public function testGenerateDeterministic(): void
    {
        $url1 = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');
        $url2 = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertSame($url1, $url2);
    }

    /**
     * Like <twig:Image>: no variant, no signature — a signed raster URL would make the controller rasterize an
     * untrusted SVG (and collide with the passthrough cache file).
     */
    /**
     * The signature does not cover the format, so the controller answers 404 for anything but the configured formats
     * and the source's fallback. An exception at render time beats a dead URL discovered in a sent e-mail.
     */
    public function testFormatTheControllerWouldNotServeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Format "png" is not generated for "uploads/photo.jpg"');

        $this->generator->generate('uploads/photo.jpg', 800, null, null, 80, 'png');
    }

    public function testTheFallbackFormatOfTheSourceIsAlwaysOffered(): void
    {
        self::assertStringEndsWith('.jpeg', $this->generator->generate('uploads/photo.jpg', 800, null, null, 80, 'jpeg'));
        self::assertStringEndsWith('.png', $this->generator->generate('icons/logo.png', 800, null, null, 80, 'png'));
    }

    public function testWithoutFormatWebpIsUsedWhileItIsConfigured(): void
    {
        self::assertStringEndsWith('.webp', $this->generator->generate('uploads/photo.jpg', 800));
    }

    public function testWithoutFormatAndWithoutWebpConfiguredTheSourceFallbackIsUsed(): void
    {
        // A literal 'webp' default would produce URLs the controller answers with 404.
        $generator = new ImageUrlGenerator(new CachePathResolver(new UrlSigner('test-secret')), new FormatNegotiator(['avif']), '/_image');

        self::assertStringEndsWith('.jpeg', $generator->generate('uploads/photo.jpg', 800));
        self::assertStringEndsWith('.png', $generator->generate('icons/logo.png', 800));
    }

    public function testSvgSourceYieldsThePassthroughUrl(): void
    {
        self::assertSame('/_image/icons/logo.svg', $this->generator->generate('icons/logo.svg', 800, 600, 'cover', 80, 'avif'));
        self::assertSame('/_image/icons/logo.svg', $this->generator->generateFromRequest(Request::create('/'), 'icons/logo.svg', 800));
        self::assertSame('/_image/%40reference/icons/Logo.SVG', $this->generator->generate('@reference/icons/Logo.SVG', 800));
    }

    public function testDefaultSourcePrefixYieldsTheSameUrlAsNoPrefix(): void
    {
        self::assertSame(
            $this->generator->generate('uploads/photo.jpg', 800, null, null, 80, 'webp'),
            $this->generator->generate('@default/uploads/photo.jpg', 800, null, null, 80, 'webp'),
        );
    }

    public function testNamedSourcePrefixIsPartOfTheUrl(): void
    {
        $url = $this->generator->generate('@reference/uploads/photo.jpg', 800, null, null, 80, 'webp');

        self::assertStringStartsWith('/_image/%40reference/uploads/photo.jpg/', $url);
        self::assertNotSame($this->generator->generate('uploads/photo.jpg', 800, null, null, 80, 'webp'), $url);
    }
}
