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

    public function testGenerateDeterministic(): void
    {
        $url1 = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');
        $url2 = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');

        self::assertSame($url1, $url2);
    }
}
