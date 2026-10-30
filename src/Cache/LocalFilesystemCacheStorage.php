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
            if ('' === $segment || '.' === $segment || '..' === $segment || str_contains($segment, '\\')) {
                throw new \InvalidArgumentException(\sprintf('Invalid cache path segment (empty, ".", ".." or containing "\\") in: %s', $cachePath));
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

        // flock() on the target directory itself: no lock files to leave behind or to purge, and the
        // variants of one source share a single lock (meta.json and blur.txt go through the lock-free write()).
        $lockHandle = $this->lockDirectory($dir);

        // If we can't obtain a lock (Windows, exotic FS), fall back to an unlocked write.
        // Correctness is preserved thanks to atomic rename; we just lose thundering-herd protection.
        if (null === $lockHandle) {
            $this->writeViaCallback($cachePath, $writer);

            return;
        }

        try {
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

    /**
     * Creates $dir and takes its lock. A purge may remove the directory while the request waits for the lock or right
     * before it opens it — writing there would fail with a 500. The lock is then taken again on the directory that
     * exists now (the open handle pins the old inode, so a recreated directory never matches it).
     *
     * @return resource|null the held lock, or null where directories cannot be locked
     */
    private function lockDirectory(string $dir)
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            // PHP caches the last stat(): without this, is_dir()/stat() can report the removed directory as present.
            clearstatcache(true, $dir);
            $this->ensureDir($dir);
            $handle = @fopen($dir, 'r');

            if (false === $handle) {
                // A purge removed the directory between ensureDir() and fopen(): create it again. Where directories
                // cannot be opened at all (Windows) it exists, and the write goes unlocked.
                clearstatcache(true, $dir);

                if (!is_dir($dir)) {
                    continue;
                }

                return null;
            }

            if (!flock($handle, \LOCK_EX)) {
                fclose($handle);

                return null;
            }

            $locked = fstat($handle);
            clearstatcache(true, $dir);
            $current = @stat($dir);

            if (false !== $locked && false !== $current && $locked['ino'] === $current['ino'] && $locked['dev'] === $current['dev']) {
                return $handle;
            }

            flock($handle, \LOCK_UN);
            fclose($handle);
        }

        // Purged three times in a row while waiting: write unlocked, the rename stays atomic.
        clearstatcache(true, $dir);
        $this->ensureDir($dir);

        return null;
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

        // A variants directory, or a directory prefix such as "uploads" or "@reference" — the whole
        // subtree goes, including the variants directories of every source image below it.
        $count = $this->deleteContents($path);
        @rmdir($path);

        return $count;
    }

    public function purgeAll(): int
    {
        if (!is_dir($this->cacheDirectory)) {
            return 0;
        }

        return $this->deleteContents($this->cacheDirectory);
    }

    /**
     * Deletes everything below $dir (not $dir itself) and returns the number of files removed.
     *
     * No locks: a request encoding into a directory at that very moment can lose its staging file or directory and
     * fail once — the next request generates the variant again. A purge that skipped locked directories lost
     * invalidations (deleteBySource() during an encode deleted nothing) and deleted nothing at all on NFS, where
     * flock() on a directory always fails. rmdir() failures are deliberately ignored: a concurrent request may have
     * written a fresh variant into a directory after its old files were unlinked, and that directory must stay.
     */
    private function deleteContents(string $dir): int
    {
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                unlink($file->getPathname());
                ++$count;
            } elseif ($file->isDir()) {
                @rmdir($file->getPathname());
            }
        }

        return $count;
    }
}
