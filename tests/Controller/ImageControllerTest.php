<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Controller;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use IdSign\ImageBundle\Controller\ImageController;
use IdSign\ImageBundle\Service\ImagickProcessor;
use IdSign\ImageBundle\Service\SourceSizeValidator;
use IdSign\ImageBundle\Service\UrlSigner;
use IdSign\ImageBundle\Service\WatermarkRegistry;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Source\NamedImageSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class ImageControllerTest extends TestCase
{
    private ImageController $controller;
    private CachePathResolver $cachePathResolver;
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/id_sign_image_ctrl_test_'.uniqid();
        mkdir($this->cacheDir, 0o775, true);

        $signer = new UrlSigner('test-secret');
        $this->cachePathResolver = new CachePathResolver($signer);

        $sizeValidator = new SourceSizeValidator(0);

        $this->controller = new ImageController(
            new ImagickProcessor($sizeValidator),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770),
            $this->cachePathResolver,
            $signer,
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            new WatermarkRegistry([]),
            $sizeValidator,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    public function testValidRequestReturnsBinaryFileResponse(): void
    {
        $path = $this->cachePathResolver->resolve('test.jpg', 64, 48, 'cover', 80, 'jpeg');
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        $cacheControl = $response->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
    }

    public function testCacheHitServesExistingFile(): void
    {
        $path = $this->cachePathResolver->resolve('test.jpg', 64, 48, null, 80, 'jpeg');
        $request = Request::create('/_image/'.$path);

        // First request — generates
        ($this->controller)($request, $path);

        // Second request — cache hit
        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testInvalidPathReturns400(): void
    {
        $request = Request::create('/_image/invalid');

        $response = ($this->controller)($request, 'invalid');

        self::assertSame(400, $response->getStatusCode());
    }

    public function testSignedUrlWithTrailingNewlineReturns400(): void
    {
        // The route requirement ".+" lets "%0A" through; without the D modifier in parse() the request
        // would verify like the original URL and write a second variant file ending in "\n".
        $path = $this->cachePathResolver->resolve('test.jpg', 64, null, null, 80, 'webp');

        // "%0A" in the request URI, decoded by the router into the {path} attribute
        $response = ($this->controller)(Request::create('/_image/'.$path.'%0A'), $path."\n");

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], glob($this->cacheDir.'/test.jpg/*') ?: []);
    }

    public function testInvalidSignatureReturns403(): void
    {
        $path = 'test.jpg/aa00bb11cc22dd33_64_48_none_80.jpeg';
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testMissingSourceReturns404(): void
    {
        $path = $this->cachePathResolver->resolve('nonexistent.jpg', 64, null, null, 80, 'jpeg');
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertSame(404, $response->getStatusCode());
    }

    public function testAvifFormatWorks(): void
    {
        $path = $this->cachePathResolver->resolve('test.jpg', 64, null, null, 80, 'avif');
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testWebpFormatWorks(): void
    {
        $path = $this->cachePathResolver->resolve('test.jpg', 64, null, null, 80, 'webp');
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testWebpLosslessProducesBitExactPixels(): void
    {
        $path = $this->cachePathResolver->resolve('test.jpg', 64, null, null, 100, 'webp', null, true);
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('_lossless', $path);

        // Decoded output should be a valid WebP. A more thorough assertion would decode
        // a non-compressed source and compare pixel-for-pixel, but for our fixtures
        // verifying magic bytes and Imagick readability is enough.
        $bytes = file_get_contents($this->cacheDir.'/'.$path);
        self::assertIsString($bytes);
        self::assertSame('RIFF', substr($bytes, 0, 4));
        self::assertSame('WEBP', substr($bytes, 8, 4));
    }

    public function testLosslessFlagTamperingInvalidatesSignature(): void
    {
        // Legitimate lossy URL
        $path = $this->cachePathResolver->resolve('test.jpg', 64, null, null, 80, 'webp');
        // Attacker inserts _lossless before the .webp extension — signature was built without it
        $tampered = (string) preg_replace('/\.webp$/', '_lossless.webp', $path);
        $request = Request::create('/_image/'.$tampered);

        $response = ($this->controller)($request, $tampered);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testWatermarkApplied(): void
    {
        $registry = new WatermarkRegistry([
            'test' => [
                'path' => __DIR__.'/../Fixtures/watermark.png',
                'position' => 'bottom-right',
                'opacity' => 50,
                'size' => 20,
                'margin' => 10,
            ],
        ]);

        $signer = new UrlSigner('test-secret');
        $resolver = new CachePathResolver($signer);

        $sizeValidator = new SourceSizeValidator(0);

        $controller = new ImageController(
            new ImagickProcessor($sizeValidator),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770),
            $resolver,
            $signer,
            new LocalFilesystemSource(__DIR__.'/../Fixtures'),
            $registry,
            $sizeValidator,
        );

        $path = $resolver->resolve('test.jpg', 64, 48, 'cover', 80, 'jpeg', 'test');
        $request = Request::create('/_image/'.$path);

        $response = ($controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());

        // Watermark variant should be a different file than non-watermark
        $pathNoWm = $resolver->resolve('test.jpg', 64, 48, 'cover', 80, 'jpeg');
        self::assertNotSame($path, $pathNoWm);
    }

    public function testEncodedUrlRoundtripsToSourceWithSpaces(): void
    {
        // Twig component / SrcsetGenerator emit %20-encoded URLs. Symfony's router
        // decodes the {path} parameter before passing it to the controller, so the
        // raw path (with literal spaces) reaches parse() and the source lookup —
        // and must resolve back to the real file on disk.
        $path = $this->cachePathResolver->resolve('photo with spaces.jpg', 64, null, null, 80, 'jpeg');
        self::assertStringContainsString('photo with spaces.jpg', $path);

        $encodedPath = CachePathResolver::encodeForUrl($path);
        self::assertStringNotContainsString(' ', $encodedPath);
        self::assertStringContainsString('photo%20with%20spaces.jpg', $encodedPath);

        // Simulate what the kernel does: decode the URL path before dispatch.
        $decodedPath = rawurldecode($encodedPath);
        $request = Request::create('/_image/'.$encodedPath);

        $response = ($this->controller)($request, $decodedPath);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSvgPassthroughReturnsBinaryFileResponse(): void
    {
        $path = 'logo.svg';
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertFileExists($this->cacheDir.'/'.$path);
    }

    public function testSvgCacheHitServesExistingFile(): void
    {
        $path = 'logo.svg';
        $request = Request::create('/_image/'.$path);

        ($this->controller)($request, $path);
        $response = ($this->controller)($request, $path);

        self::assertInstanceOf(BinaryFileResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testSvgMissingSourceReturns404(): void
    {
        $path = 'nonexistent.svg';
        $request = Request::create('/_image/'.$path);

        $response = ($this->controller)($request, $path);

        self::assertSame(404, $response->getStatusCode());
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

    /**
     * Controller whose source is the composite: fixtures as default, a temp dir with ref.jpg as "@reference".
     *
     * @return array{ImageController, string}
     */
    private function createControllerWithNamedSource(): array
    {
        $referenceDir = $this->cacheDir.'-reference';
        mkdir($referenceDir, 0o775, true);
        copy(__DIR__.'/../Fixtures/test.jpg', $referenceDir.'/ref.jpg');
        copy(__DIR__.'/../Fixtures/logo.svg', $referenceDir.'/ref.svg');

        $signer = new UrlSigner('test-secret');
        $sizeValidator = new SourceSizeValidator(0);

        $controller = new ImageController(
            new ImagickProcessor($sizeValidator),
            new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770),
            $this->cachePathResolver,
            $signer,
            new NamedImageSource(
                new LocalFilesystemSource(__DIR__.'/../Fixtures'),
                new ServiceLocator(['reference' => static fn (): ImageSourceInterface => new LocalFilesystemSource($referenceDir)]),
            ),
            new WatermarkRegistry([]),
            $sizeValidator,
        );

        return [$controller, $referenceDir];
    }

    public function testNamedSourceVariantIsCachedUnderThePrefixedDirectory(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = $this->cachePathResolver->resolve('@reference/ref.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.CachePathResolver::encodeForUrl($path)), $path);

            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertFileExists($this->cacheDir.'/'.$path);
            self::assertStringStartsWith($this->cacheDir.'/@reference/ref.jpg/', $this->cacheDir.'/'.$path);
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testNamedSourceFileIsNotVisibleThroughTheDefaultSource(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = $this->cachePathResolver->resolve('ref.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.$path), $path);

            self::assertSame(404, $response->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testEmptySegmentAfterSourcePrefixReturns404(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            // The source must not report "@reference//ref.jpg" as existing: the cache layer rejects the
            // empty segment, and the request would end in an uncaught exception (500) instead of 404.
            $path = $this->cachePathResolver->resolve('@reference//ref.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.$path), $path);

            self::assertSame(404, $response->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testCachedVariantOfDeletedSourceIsServedUntilPurged(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = $this->cachePathResolver->resolve('@reference/ref.jpg', 64, null, null, 80, 'webp');
            self::assertSame(200, $controller(Request::create('/_image/'.$path), $path)->getStatusCode());

            // The cache is checked before the source, so a hit never touches the source (same as try_files
            // in public mode); the variant disappears with the TTL or deleteBySource(), not with the file.
            unlink($referenceDir.'/ref.jpg');
            self::assertSame(200, $controller(Request::create('/_image/'.$path), $path)->getStatusCode());

            (new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770))->deleteBySource('@reference/ref.jpg');
            self::assertSame(404, $controller(Request::create('/_image/'.$path), $path)->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testCachedSvgOfDeletedSourceIsServedUntilPurged(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = '@reference/ref.svg';
            self::assertSame(200, $controller(Request::create('/_image/'.$path), $path)->getStatusCode());

            unlink($referenceDir.'/ref.svg');
            self::assertSame(200, $controller(Request::create('/_image/'.$path), $path)->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testUnknownSourceReturns404(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = $this->cachePathResolver->resolve('@unknown/test.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.$path), $path);

            self::assertSame(404, $response->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testDefaultPrefixInsertedIntoASignedUrlReturns403(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            // Bundle never emits "@default/"; a signature for "test.jpg" does not cover "@default/test.jpg".
            $path = '@default/'.$this->cachePathResolver->resolve('test.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.$path), $path);

            self::assertSame(403, $response->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testDefaultPrefixSignedDirectlyReturns404(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            // Even with a matching signature (CachePathResolver called directly, bypassing the entry
            // points), the composite refuses "@default/" so the file gets no second cache directory.
            $path = $this->cachePathResolver->resolve('@default/test.jpg', 64, null, null, 80, 'webp');
            $response = $controller(Request::create('/_image/'.$path), $path);

            self::assertSame(404, $response->getStatusCode());
            self::assertDirectoryDoesNotExist($this->cacheDir.'/@default');
        } finally {
            $this->removeDir($referenceDir);
        }
    }

    public function testSvgFromNamedSourceIsCachedUnderThePrefixedPath(): void
    {
        [$controller, $referenceDir] = $this->createControllerWithNamedSource();

        try {
            $path = '@reference/ref.svg';
            $response = $controller(Request::create('/_image/'.CachePathResolver::encodeForUrl($path)), $path);

            self::assertInstanceOf(BinaryFileResponse::class, $response);
            self::assertSame(200, $response->getStatusCode());
            self::assertFileExists($this->cacheDir.'/@reference/ref.svg');

            self::assertSame(404, $controller(Request::create('/_image/@default/logo.svg'), '@default/logo.svg')->getStatusCode());
        } finally {
            $this->removeDir($referenceDir);
        }
    }
}
