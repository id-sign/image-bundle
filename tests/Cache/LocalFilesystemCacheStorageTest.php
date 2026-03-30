<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Cache;

use IdSign\ImageBundle\Cache\LocalFilesystemCacheStorage;
use PHPUnit\Framework\TestCase;

class LocalFilesystemCacheStorageTest extends TestCase
{
    private string $cacheDir;
    private LocalFilesystemCacheStorage $storage;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/id_sign_image_test_'.uniqid();
        mkdir($this->cacheDir, 0o775, true);
        $this->storage = new LocalFilesystemCacheStorage($this->cacheDir, 3600);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir);
    }

    public function testHasReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->storage->has('nonexistent.avif'));
    }

    public function testWriteAndHas(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'image-data');

        $this->storage->write('ab/test.avif', $tmpFile);

        self::assertTrue($this->storage->has('ab/test.avif'));
        self::assertFalse(is_file($tmpFile)); // moved, not copied
    }

    public function testGetAbsolutePath(): void
    {
        self::assertSame($this->cacheDir.'/ab/test.avif', $this->storage->getAbsolutePath('ab/test.avif'));
    }

    public function testDelete(): void
    {
        $this->createCacheFile('test.avif');

        self::assertTrue($this->storage->has('test.avif'));

        $this->storage->delete('test.avif');

        self::assertFalse($this->storage->has('test.avif'));
    }

    public function testDeleteNonexistentDoesNotThrow(): void
    {
        $this->storage->delete('nonexistent.avif');

        $this->expectNotToPerformAssertions();
    }

    public function testPurgeAll(): void
    {
        $this->createCacheFile('a/1.avif');
        $this->createCacheFile('b/2.webp');

        $count = $this->storage->purgeAll();

        self::assertSame(2, $count);
        self::assertFalse($this->storage->has('a/1.avif'));
        self::assertFalse($this->storage->has('b/2.webp'));
    }

    public function testHasReturnsFalseForExpiredFile(): void
    {
        $storage = new LocalFilesystemCacheStorage($this->cacheDir, 1);
        $this->createCacheFile('old.avif');

        // Set mtime to 2 seconds ago
        touch($this->cacheDir.'/old.avif', time() - 2);

        self::assertFalse($storage->has('old.avif'));
    }

    private function createCacheFile(string $path): void
    {
        $absolutePath = $this->cacheDir.'/'.$path;
        $dir = \dirname($absolutePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        file_put_contents($absolutePath, 'test-data');
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
