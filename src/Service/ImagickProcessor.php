<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

class ImagickProcessor implements ImageProcessorInterface
{
    public function __construct(
        private readonly ?int $filePermissions,
        private readonly int $directoryPermissions,
    ) {
    }

    private const FORMAT_MAP = [
        'avif' => 'AVIF',
        'webp' => 'WEBP',
        'jpeg' => 'JPEG',
        'jpg' => 'JPEG',
        'png' => 'PNG',
    ];

    /**
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
    ): void {
        $imagick = new \Imagick($sourcePath);

        try {
            $this->autoRotate($imagick);

            $origWidth = $imagick->getImageWidth();
            $origHeight = $imagick->getImageHeight();
            $targetHeight = $height ?? $this->proportionalHeight($origWidth, $origHeight, $width);

            match ($fit) {
                'cover' => $this->fitCover($imagick, $width, $targetHeight),
                'contain' => $this->fitContain($imagick, $width, $targetHeight),
                'scale-down' => $this->fitScaleDown($imagick, $width, $targetHeight, $origWidth, $origHeight),
                default => $this->fitExact($imagick, $width, $targetHeight),
            };

            if (null !== $watermark) {
                $this->applyWatermark($imagick, $watermark);
            }

            $imagick->stripImage();

            $imagickFormat = self::FORMAT_MAP[$format] ?? throw new \InvalidArgumentException(\sprintf('Unsupported format: %s', $format));

            if ('JPEG' === $imagickFormat) {
                $this->flattenAlpha($imagick);
            }

            $imagick->setImageFormat($imagickFormat);
            $imagick->setImageCompressionQuality($quality);

            $outputDir = \dirname($outputPath);
            if (!is_dir($outputDir) && !mkdir($outputDir, $this->directoryPermissions, true) && !is_dir($outputDir)) {
                throw new \RuntimeException(\sprintf('Failed to create directory: %s', $outputDir));
            }

            $imagick->writeImage($outputPath);

            if (null !== $this->filePermissions) {
                chmod($outputPath, $this->filePermissions);
            }
        } finally {
            $imagick->clear();
        }
    }

    /**
     * @throws \ImagickException
     */
    private function fitCover(\Imagick $imagick, int $width, int $height): void
    {
        $imagick->cropThumbnailImage($width, $height);
    }

    /**
     * @throws \ImagickException
     */
    private function fitContain(\Imagick $imagick, int $width, int $height): void
    {
        $imagick->thumbnailImage($width, $height, true);
    }

    /**
     * @throws \ImagickException
     */
    private function fitScaleDown(\Imagick $imagick, int $width, int $height, int $origWidth, int $origHeight): void
    {
        if ($origWidth <= $width && $origHeight <= $height) {
            return;
        }

        $this->fitContain($imagick, $width, $height);
    }

    /**
     * @throws \ImagickException
     */
    private function fitExact(\Imagick $imagick, int $width, int $height): void
    {
        $imagick->resizeImage($width, $height, \Imagick::FILTER_LANCZOS, 1);
    }

    private function proportionalHeight(int $origWidth, int $origHeight, int $targetWidth): int
    {
        if (0 === $origWidth) {
            return 1;
        }

        return max(1, (int) round($origHeight * $targetWidth / $origWidth));
    }

    /**
     * @throws \ImagickException
     */
    private function applyWatermark(\Imagick $imagick, WatermarkOptions $options): void
    {
        $watermark = new \Imagick($options->path);

        try {
            $imgWidth = $imagick->getImageWidth();
            $imgHeight = $imagick->getImageHeight();
            $targetWidth = (int) round($imgWidth * $options->size / 100);
            $watermark->thumbnailImage($targetWidth, 0);

            if ($options->opacity < 100) {
                $watermark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $options->opacity / 100, \Imagick::CHANNEL_ALPHA);
            }

            $wmWidth = $watermark->getImageWidth();
            $wmHeight = $watermark->getImageHeight();

            [$x, $y] = $this->calculateWatermarkPosition(
                $imgWidth, $imgHeight, $wmWidth, $wmHeight, $options->position, $options->margin,
            );

            $imagick->compositeImage($watermark, \Imagick::COMPOSITE_OVER, $x, $y);
        } finally {
            $watermark->clear();
        }
    }

    /**
     * @return array{int, int}
     */
    private function calculateWatermarkPosition(
        int $imgW, int $imgH, int $wmW, int $wmH, string $position, int $margin,
    ): array {
        return match ($position) {
            'top-left' => [$margin, $margin],
            'top-center' => [(int) (($imgW - $wmW) / 2), $margin],
            'top-right' => [$imgW - $wmW - $margin, $margin],
            'center-left' => [$margin, (int) (($imgH - $wmH) / 2)],
            'center' => [(int) (($imgW - $wmW) / 2), (int) (($imgH - $wmH) / 2)],
            'center-right' => [$imgW - $wmW - $margin, (int) (($imgH - $wmH) / 2)],
            'bottom-left' => [$margin, $imgH - $wmH - $margin],
            'bottom-center' => [(int) (($imgW - $wmW) / 2), $imgH - $wmH - $margin],
            'bottom-right' => [$imgW - $wmW - $margin, $imgH - $wmH - $margin],
            default => [$imgW - $wmW - $margin, $imgH - $wmH - $margin],
        };
    }

    /**
     * @throws \ImagickException
     */
    private function flattenAlpha(\Imagick $imagick): void
    {
        if (!$imagick->getImageAlphaChannel()) {
            return;
        }

        $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
        $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
    }

    /**
     * @throws \ImagickException
     */
    private function autoRotate(\Imagick $imagick): void
    {
        $orientation = $imagick->getImageOrientation();

        match ($orientation) {
            \Imagick::ORIENTATION_BOTTOMRIGHT => $imagick->rotateImage(new \ImagickPixel('none'), 180),
            \Imagick::ORIENTATION_RIGHTTOP => $imagick->rotateImage(new \ImagickPixel('none'), 90),
            \Imagick::ORIENTATION_LEFTBOTTOM => $imagick->rotateImage(new \ImagickPixel('none'), -90),
            default => null,
        };

        $imagick->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
    }
}
