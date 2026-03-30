<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

interface CacheStorageInterface
{
    /**
     * Check if a cached variant exists and is not expired.
     */
    public function has(string $cachePath): bool;

    /**
     * Get the absolute filesystem path for a cached variant.
     */
    public function getAbsolutePath(string $cachePath): string;

    /**
     * Write a processed image to cache (moves source file to cache location).
     */
    public function write(string $cachePath, string $sourcePath): void;

    /**
     * Delete a cached variant.
     */
    public function delete(string $cachePath): void;

    /**
     * Delete all cached variants for a specific source image.
     *
     * @return int Number of deleted files
     */
    public function deleteBySource(string $src): int;

    /**
     * Delete all cached files.
     *
     * @return int Number of deleted files
     */
    public function purgeAll(): int;
}
