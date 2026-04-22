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
        $this->storage = new LocalFilesystemCacheStorage($this->cacheDir, 3600, 0o660, 0o770);
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
        $this->createCacheFile('icons/logo.svg');

        $count = $this->storage->purgeAll();

        self::assertSame(3, $count);
        self::assertFalse($this->storage->has('a/1.avif'));
        self::assertFalse($this->storage->has('b/2.webp'));
        self::assertFalse($this->storage->has('icons/logo.svg'));
    }

    public function testHasReturnsFalseForExpiredFile(): void
    {
        $storage = new LocalFilesystemCacheStorage($this->cacheDir, 1, 0o660, 0o770);
        $this->createCacheFile('old.avif');

        // Set mtime to 2 seconds ago
        touch($this->cacheDir.'/old.avif', time() - 2);

        self::assertFalse($storage->has('old.avif'));
    }

    public function testDeleteBySourceRemovesRasterVariants(): void
    {
        $this->createCacheFile('uploads/photo.jpg/abc_800_600_cover_80.avif');
        $this->createCacheFile('uploads/photo.jpg/abc_400_300_none_75.webp');

        $count = $this->storage->deleteBySource('uploads/photo.jpg');

        self::assertSame(2, $count);
        self::assertDirectoryDoesNotExist($this->cacheDir.'/uploads/photo.jpg');
    }

    public function testDeleteBySourceRemovesSvgFile(): void
    {
        $this->createCacheFile('icons/logo.svg');

        $count = $this->storage->deleteBySource('icons/logo.svg');

        self::assertSame(1, $count);
        self::assertFileDoesNotExist($this->cacheDir.'/icons/logo.svg');
    }

    public function testDeleteBySourceReturnsZeroForNonexistent(): void
    {
        self::assertSame(0, $this->storage->deleteBySource('nonexistent.jpg'));
    }

    public function testGetAbsolutePathRejectsDotDotSegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->getAbsolutePath('../escape.jpg');
    }

    public function testGetAbsolutePathRejectsEmptySegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->getAbsolutePath('foo//bar.jpg');
    }

    public function testHasReturnsFalseForTraversalAttempt(): void
    {
        self::assertFalse($this->storage->has('../escape.jpg'));
    }

    public function testDeleteIsSilentForTraversalAttempt(): void
    {
        $this->storage->delete('../escape.jpg');

        $this->expectNotToPerformAssertions();
    }

    public function testAtomicWriteSurvivesCrossFilesystemTmp(): void
    {
        // Simulate cross-FS tmp by using a tmpfile outside the cache dir
        $tmpFile = tempnam(sys_get_temp_dir(), 'id_sign_image_atomic_');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'payload');

        $this->storage->write('ab/cross-fs.avif', $tmpFile);

        self::assertTrue($this->storage->has('ab/cross-fs.avif'));
        self::assertSame('payload', file_get_contents($this->cacheDir.'/ab/cross-fs.avif'));
        self::assertFalse(is_file($tmpFile));
    }

    public function testWriteLockedInvokesWriterAndCommits(): void
    {
        $called = false;

        $this->storage->writeLocked('ab/locked.webp', static function (string $tmpPath) use (&$called): void {
            $called = true;
            file_put_contents($tmpPath, 'locked-payload');
        });

        self::assertTrue($called);
        self::assertTrue($this->storage->has('ab/locked.webp'));
        self::assertSame('locked-payload', file_get_contents($this->cacheDir.'/ab/locked.webp'));
    }

    public function testWriteLockedSkipsWriterWhenAlreadyCached(): void
    {
        // Prime the cache
        $this->storage->writeLocked('ab/primed.webp', static function (string $tmpPath): void {
            file_put_contents($tmpPath, 'first');
        });

        $calledAgain = false;

        // Second call with same path — writer must NOT run, cache already satisfies the request.
        $this->storage->writeLocked('ab/primed.webp', static function (string $tmpPath) use (&$calledAgain): void {
            $calledAgain = true;
            file_put_contents($tmpPath, 'second');
        });

        self::assertFalse($calledAgain);
        self::assertSame('first', file_get_contents($this->cacheDir.'/ab/primed.webp'));
    }

    public function testWriteLockedCleansIntermediateOnWriterFailure(): void
    {
        try {
            $this->storage->writeLocked('ab/fail.webp', static function (): void {
                throw new \RuntimeException('writer exploded');
            });
            self::fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            self::assertSame('writer exploded', $e->getMessage());
        }

        self::assertFalse($this->storage->has('ab/fail.webp'));

        // No leftover intermediate tmp files
        $leftovers = glob($this->cacheDir.'/ab/.*.tmp');
        self::assertSame([], $leftovers ?: []);
    }

    public function testWriteLockedRejectsTraversalPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->writeLocked('../escape.webp', static function (string $tmpPath): void {
            file_put_contents($tmpPath, 'x');
        });
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
