<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

class LocalFilesystemSource implements ImageSourceInterface
{
    public function __construct(
        private readonly string $basePath,
    ) {
    }

    public function exists(string $path): bool
    {
        return is_file($this->getAbsolutePath($path));
    }

    public function getAbsolutePath(string $path): string
    {
        return $this->basePath.'/'.ltrim($path, '/');
    }
}
