<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

class SourceSizeValidator
{
    public function __construct(
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @throws \RuntimeException when the file exceeds the configured byte limit
     */
    public function assertFits(string $absolutePath): void
    {
        if (0 === $this->maxBytes) {
            return;
        }

        $size = @filesize($absolutePath);
        if (false === $size) {
            // File existence is the caller's responsibility; silently skip here.
            return;
        }

        if ($size > $this->maxBytes) {
            throw new \RuntimeException(\sprintf('Source file exceeds configured max_source_bytes: %d > %d (%s)', $size, $this->maxBytes, $absolutePath));
        }
    }
}
