<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

interface ImageSourceInterface
{
    /**
     * Check if a source image exists.
     */
    public function exists(string $path): bool;

    /**
     * Get the absolute filesystem path to the source image.
     *
     * For local sources, this returns the direct path.
     * For remote sources (e.g. Flysystem/S3), implementations may download
     * the file to a temporary directory and return that path.
     */
    public function getAbsolutePath(string $path): string;
}
