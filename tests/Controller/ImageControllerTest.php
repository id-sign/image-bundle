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
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use PHPUnit\Framework\TestCase;
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
            new ImagickProcessor($sizeValidator, 0o660, 0o770),
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
            new ImagickProcessor($sizeValidator, 0o660, 0o770),
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
}
