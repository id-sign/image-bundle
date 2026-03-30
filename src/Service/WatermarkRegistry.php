<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

class WatermarkRegistry
{
    /** @var array<string, WatermarkOptions> */
    private readonly array $profiles;

    /**
     * @param array<string, array{path: string, position: string, opacity: int, size: int, margin: int}> $watermarks
     */
    public function __construct(array $watermarks)
    {
        $profiles = [];
        foreach ($watermarks as $name => $config) {
            $profiles[$name] = new WatermarkOptions(
                $config['path'],
                $config['position'],
                $config['opacity'],
                $config['size'],
                $config['margin'],
            );
        }
        $this->profiles = $profiles;
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    public function get(string $name): WatermarkOptions
    {
        if (!isset($this->profiles[$name])) {
            throw new \InvalidArgumentException(\sprintf('Watermark profile "%s" is not configured.', $name));
        }

        return $this->profiles[$name];
    }
}
