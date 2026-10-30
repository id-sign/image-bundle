<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Cache;

use IdSign\ImageBundle\Cache\CacheFiles;
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
        $this->createCacheFile('a.jpg/aa00bb11cc22dd33_800_auto_none_80.avif');
        $this->createCacheFile('b.png/aa00bb11cc22dd33_400_300_cover_75_lossless_wm-brand-logo.webp');
        $this->createCacheFile('b.png/meta.json');
        $this->createCacheFile('b.png/blur.txt');
        $this->createCacheFile('b.png/.0123456789abcdef.tmp');
        $this->createSvgCopy('icons/logo.svg');

        $count = $this->storage->purgeAll();

        self::assertSame(6, $count, 'the SVG marker is not counted');
        self::assertSame([], glob($this->cacheDir.'/*') ?: [], 'emptied directories are removed');
        self::assertFileDoesNotExist($this->cacheDir.'/icons/.logo.svg.id-sign-image');
    }

    /**
     * The default cache.path is the document root plus route_prefix: with route_prefix /images it is public/images,
     * which may hold the application's own files.
     */
    public function testPurgeAllLeavesFilesTheBundleDidNotWrite(): void
    {
        $this->createCacheFile('photo.jpg/aa00bb11cc22dd33_800_auto_none_80.avif');
        $this->createCacheFile('photo.jpg/meta.json');
        $this->createSvgCopy('icons/cached.svg');
        $foreign = ['logo.png', 'icons/cart.svg', 'css/site.css', 'meta.json', 'banner-800_600.webp', 'photo.jpg/notes.txt'];
        foreach ($foreign as $path) {
            $this->createCacheFile($path);
        }
        mkdir($this->cacheDir.'/empty');

        $count = $this->storage->purgeAll();

        self::assertSame(3, $count);
        foreach ($foreign as $path) {
            self::assertFileExists($this->cacheDir.'/'.$path);
        }
        self::assertFileDoesNotExist($this->cacheDir.'/icons/cached.svg');
        self::assertDirectoryExists($this->cacheDir.'/empty', 'a directory nothing was deleted from stays');
    }

    public function testStrayVariantWithLeadingZerosIsPurged(): void
    {
        // Cached by versions that accepted "_0800_" in a signed URL.
        $this->createCacheFile('photo.jpg/aa00bb11cc22dd33_0800_auto_none_080.avif');

        self::assertSame(1, $this->storage->purgeAll());
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
        $this->createCacheFile('uploads/photo.jpg/aa00bb11cc22dd33_800_600_cover_80.avif');
        $this->createCacheFile('uploads/photo.jpg/aa00bb11cc22dd33_400_300_none_75.webp');

        $count = $this->storage->deleteBySource('uploads/photo.jpg');

        self::assertSame(2, $count);
        self::assertDirectoryDoesNotExist($this->cacheDir.'/uploads/photo.jpg');
    }

    public function testDeleteBySourceRemovesDirectoryPrefixRecursively(): void
    {
        $this->createCacheFile('@reference/logo.png/aa00bb11cc22dd33_800_auto_none_80.avif');
        $this->createCacheFile('@reference/logo.png/meta.json');
        $this->createSvgCopy('@reference/icons/pin.svg');
        $this->createCacheFile('uploads/photo.jpg/aa00bb11cc22dd33_800_auto_none_80.avif');

        $count = $this->storage->deleteBySource('@reference');

        self::assertSame(3, $count);
        self::assertDirectoryDoesNotExist($this->cacheDir.'/@reference');
        self::assertFileExists($this->cacheDir.'/uploads/photo.jpg/aa00bb11cc22dd33_800_auto_none_80.avif');
    }

    public function testDeleteBySourceRemovesSvgFile(): void
    {
        $this->createSvgCopy('icons/logo.svg');

        $count = $this->storage->deleteBySource('icons/logo.svg');

        self::assertSame(1, $count);
        self::assertFileDoesNotExist($this->cacheDir.'/icons/logo.svg');
        self::assertFileDoesNotExist($this->cacheDir.'/icons/.logo.svg.id-sign-image');
    }

    public function testDeleteBySourceLeavesAStaticSvg(): void
    {
        $this->createCacheFile('icons/logo.svg');

        self::assertSame(0, $this->storage->deleteBySource('icons/logo.svg'));
        self::assertFileExists($this->cacheDir.'/icons/logo.svg');
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

    public function testGetAbsolutePathRejectsBackslashInSegment(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->getAbsolutePath('..\\escape.jpg');
    }

    public function testGetAbsolutePathRejectsDotSegment(): void
    {
        // deleteBySource('.') would otherwise wipe the whole cache and then fail on rmdir().
        $this->expectException(\InvalidArgumentException::class);

        $this->storage->getAbsolutePath('.');
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

    public function testWriteLockedLeavesNoLockFiles(): void
    {
        $this->storage->writeLocked('ab/locked.webp', static function (string $tmpPath): void {
            file_put_contents($tmpPath, 'payload');
        });

        self::assertSame(['locked.webp'], array_values(array_diff((array) scandir($this->cacheDir.'/ab'), ['.', '..'])));
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

    /**
     * An SVG passthrough copy as ImageController writes it: the file plus its marker.
     */
    private function createSvgCopy(string $path): void
    {
        $this->createCacheFile($path);
        $this->createCacheFile(CacheFiles::svgMarker($path));
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
