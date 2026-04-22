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
        try {
            return is_file($this->getAbsolutePath($path));
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function getAbsolutePath(string $path): string
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ('' === $segment || '..' === $segment) {
                throw new \InvalidArgumentException(\sprintf('Invalid path segment (empty or "..") in: %s', $path));
            }
        }

        return $this->basePath.'/'.ltrim($path, '/');
    }
}
