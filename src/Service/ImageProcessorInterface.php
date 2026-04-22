<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

interface ImageProcessorInterface
{
    /**
     * Process an image: resize, apply fit mode, convert format, set quality.
     *
     * @param string      $sourcePath Absolute path to the source image
     * @param string      $outputPath Absolute path to write the processed image
     * @param int         $width      Target width in pixels
     * @param int|null    $height     Target height in pixels (null = proportional)
     * @param string|null $fit        Fit mode: 'cover', 'contain', 'scale-down', or null
     * @param string      $format     Output format: 'avif', 'webp', 'jpeg', 'png'
     * @param int         $quality    Output quality (1-100) — ignored when $lossless is true
     * @param bool        $lossless   Use lossless encoder for formats that support it (webp, avif).
     *                                Silently ignored for JPEG (no lossless mode) and PNG (already lossless).
     *
     * @throws \ImagickException
     */
    public function process(
        string $sourcePath,
        string $outputPath,
        int $width,
        ?int $height,
        ?string $fit,
        string $format,
        int $quality,
        ?WatermarkOptions $watermark = null,
        bool $lossless = false,
    ): void;
}
