<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

class LocalFilesystemCacheStorage implements CacheStorageInterface
{
    public function __construct(
        private readonly string $cacheDirectory,
        private readonly int $ttl,
    ) {
    }

    public function has(string $cachePath): bool
    {
        $absolutePath = $this->getAbsolutePath($cachePath);

        if (!is_file($absolutePath)) {
            return false;
        }

        return (time() - filemtime($absolutePath)) < $this->ttl;
    }

    public function getAbsolutePath(string $cachePath): string
    {
        return $this->cacheDirectory.'/'.ltrim($cachePath, '/');
    }

    public function write(string $cachePath, string $sourcePath): void
    {
        $absolutePath = $this->getAbsolutePath($cachePath);
        $dir = \dirname($absolutePath);

        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to create directory: %s', $dir));
        }

        if (!rename($sourcePath, $absolutePath)) {
            throw new \RuntimeException(\sprintf('Failed to move file from %s to %s', $sourcePath, $absolutePath));
        }
    }

    public function delete(string $cachePath): void
    {
        $absolutePath = $this->getAbsolutePath($cachePath);

        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }

    public function deleteBySource(string $src): int
    {
        $path = $this->getAbsolutePath($src);

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
