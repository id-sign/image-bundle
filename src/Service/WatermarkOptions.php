<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

class WatermarkOptions
{
    public function __construct(
        public readonly string $path,
        public readonly string $position,
        public readonly int $opacity,
        public readonly int $size,
        public readonly int $margin,
    ) {
    }
}
