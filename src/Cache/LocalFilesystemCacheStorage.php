<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

class LocalFilesystemCacheStorage implements CacheStorageInterface
{
    public function __construct(
        private readonly string $cacheDirectory,
        private readonly int $ttl,
        private readonly ?int $filePermissions,
        private readonly int $directoryPermissions,
    ) {
    }

    public function has(string $cachePath): bool
    {
        try {
            $absolutePath = $this->getAbsolutePath($cachePath);
        } catch (\InvalidArgumentException) {
            return false;
        }

        if (!is_file($absolutePath)) {
            return false;
        }

        return (time() - filemtime($absolutePath)) < $this->ttl;
    }

    public function getAbsolutePath(string $cachePath): string
    {
        foreach (explode('/', trim($cachePath, '/')) as $segment) {
            if ('' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException(\sprintf('Invalid cache path segment (empty or "..") in: %s', $cachePath));
            }
        }

        return $this->cacheDirectory.'/'.ltrim($cachePath, '/');
    }

    public function write(string $cachePath, string $sourcePath): void
    {
        $absolutePath = $this->getAbsolutePath($cachePath);
        $dir = \dirname($absolutePath);
        $this->ensureDir($dir);

        // Atomic write: stage into intermediate file in the SAME directory as the target,
        // then rename(). Intra-directory rename() is atomic on POSIX filesystems.
        // $sourcePath may live on a different filesystem (e.g. /tmp on tmpfs); a direct
        // rename() across filesystems falls back to non-atomic copy+unlink and can expose
        // partial files to readers that observe the cache between has() and the completed write.
        $intermediate = $this->makeIntermediatePath($dir);

        try {
            if (!@rename($sourcePath, $intermediate)) {
                if (!@copy($sourcePath, $intermediate)) {
                    throw new \RuntimeException(\sprintf('Failed to stage file from %s to %s', $sourcePath, $intermediate));
                }
                @unlink($sourcePath);
            }

            $this->commitIntermediate($intermediate, $absolutePath);
        } catch (\Throwable $e) {
            if (is_file($intermediate)) {
                @unlink($intermediate);
            }
            throw $e;
        }
    }

    public function writeLocked(string $cachePath, callable $writer): void
    {
        $absolutePath = $this->getAbsolutePath($cachePath);
        $dir = \dirname($absolutePath);
        $this->ensureDir($dir);

        $lockPath = $absolutePath.'.lock';
        $lockHandle = @fopen($lockPath, 'c');

        // If we can't obtain a lock handle (rare — permissions / exotic FS), fall back
        // to unlocked write. Correctness is preserved thanks to atomic rename; we just
        // lose thundering-herd protection in this edge case.
        if (false === $lockHandle) {
            $this->writeViaCallback($cachePath, $writer);

            return;
        }

        try {
            if (!flock($lockHandle, \LOCK_EX)) {
                $this->writeViaCallback($cachePath, $writer);

                return;
            }

            // Re-check under lock: another process may have produced the variant while we waited.
            if ($this->has($cachePath)) {
                return;
            }

            $this->writeViaCallback($cachePath, $writer);
        } finally {
            flock($lockHandle, \LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function writeViaCallback(string $cachePath, callable $writer): void
    {
        $absolutePath = $this->getAbsolutePath($cachePath);
        $dir = \dirname($absolutePath);
        $intermediate = $this->makeIntermediatePath($dir);

        try {
            $writer($intermediate);

            if (!is_file($intermediate)) {
                throw new \RuntimeException(\sprintf('Writer callback did not produce file at %s', $intermediate));
            }

            $this->commitIntermediate($intermediate, $absolutePath);
        } catch (\Throwable $e) {
            if (is_file($intermediate)) {
                @unlink($intermediate);
            }
            throw $e;
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $this->directoryPermissions, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
        }
    }

    private function makeIntermediatePath(string $dir): string
    {
        return $dir.'/.'.bin2hex(random_bytes(8)).'.tmp';
    }

    private function commitIntermediate(string $intermediate, string $absolutePath): void
    {
        if (!rename($intermediate, $absolutePath)) {
            throw new \RuntimeException(\sprintf('Failed to move file from %s to %s', $intermediate, $absolutePath));
        }

        if (null !== $this->filePermissions) {
            chmod($absolutePath, $this->filePermissions);
        }
    }

    public function delete(string $cachePath): void
    {
        try {
            $absolutePath = $this->getAbsolutePath($cachePath);
        } catch (\InvalidArgumentException) {
            return;
        }

        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }

    public function deleteBySource(string $src): int
    {
        try {
            $path = $this->getAbsolutePath($src);
        } catch (\InvalidArgumentException) {
            return 0;
        }

        // SVG: cached as a single file, no variants directory
        if (is_file($path)) {
            $this->delete($src);

            return 1;
        }

        if (!is_dir($path)) {
            return 0;
        }

        $count = 0;
        $iterator = new \DirectoryIterator($path);

        foreach ($iterator as $file) {
            if (!$file->isDot() && $file->isFile()) {
                unlink($file->getPathname());
                ++$count;
            }
        }

        rmdir($path);

        return $count;
    }

    public function purgeAll(): int
    {
        if (!is_dir($this->cacheDirectory)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
                ++$count;
            } elseif ($file->isDir()) {
                rmdir($file->getPathname());
            }
        }

        return $count;
    }
}
