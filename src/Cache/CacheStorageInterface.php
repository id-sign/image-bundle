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
     * Acquire a per-variant lock, re-check cache, and invoke $writer to produce the file
     * if still missing. Protects against thundering herd — N concurrent requests for the
     * same uncached variant cause only one write; others block on the lock and re-serve
     * the produced file on retry.
     *
     * Implementations may skip the lock if the underlying backend does not support it
     * cheaply (e.g. a remote-storage backend may accept best-effort concurrent writes).
     *
     * @param callable(string): void $writer Callback receiving an absolute path of a tmp file
     *                                       inside the target directory. Must write the full
     *                                       variant content to that path. Atomic commit to the
     *                                       final cache location is handled by the storage.
     */
    public function writeLocked(string $cachePath, callable $writer): void;

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
