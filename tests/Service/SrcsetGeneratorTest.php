<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Service;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Service\SrcsetGenerator;
use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\TestCase;

class SrcsetGeneratorTest extends TestCase
{
    private SrcsetGenerator $generator;

    protected function setUp(): void
    {
        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);
        $this->generator = new SrcsetGenerator($resolver, [640, 750, 828, 1080, 1200], '/_image');
    }

    public function testGenerateOnlyIncludesBreakpointsLessOrEqualToWidth(): void
    {
        $entries = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');

        $widths = array_column($entries, 'width');

        self::assertContains(640, $widths);
        self::assertContains(750, $widths);
        self::assertNotContains(828, $widths);
        self::assertNotContains(1080, $widths);
    }

    public function testGenerateReturnsEmptyForSmallWidth(): void
    {
        $entries = $this->generator->generate('photo.jpg', 100, null, null, 80, 'webp');

        self::assertSame([], $entries);
    }

    public function testGenerateUrlsStartWithRoutePrefix(): void
    {
        $entries = $this->generator->generate('photo.jpg', 1200, null, null, 80, 'avif');

        foreach ($entries as $entry) {
            self::assertStringStartsWith('/_image/', $entry['url']);
        }
    }

    public function testGenerateSrcsetString(): void
    {
        $srcset = $this->generator->generateSrcsetString('photo.jpg', 800, null, null, 80, 'avif');

        self::assertStringContainsString('640w', $srcset);
        self::assertStringContainsString('750w', $srcset);
        self::assertStringNotContainsString('1080w', $srcset);
    }

    public function testGenerateCalculatesProportionalHeight(): void
    {
        // 800x600, aspect ratio 0.75 → 640 breakpoint should get height 480
        $entries = $this->generator->generate('photo.jpg', 800, 600, 'cover', 80, 'avif');

        $entry640 = array_filter($entries, static fn (array $e): bool => 640 === $e['width']);
        $entry640 = array_values($entry640);

        self::assertCount(1, $entry640);
        self::assertStringContainsString('640_480_cover_80', $entry640[0]['url']);
    }
}
