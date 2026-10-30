<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use Psr\Log\LoggerInterface;

class ImagickProcessor implements ImageProcessorInterface
{
    public function __construct(
        private readonly SourceSizeValidator $sourceSizeValidator,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    private const FORMAT_MAP = [
        'avif' => 'AVIF',
        'webp' => 'WEBP',
        'jpeg' => 'JPEG',
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
        bool $lossless = false,
    ): void {
        $this->sourceSizeValidator->assertFits($sourcePath);

        $imagick = new \Imagick();

        try {
            // Header only (~0.04 ms): enforce the pixel limit before anything is decoded, and find out whether
            // this is a JPEG that can be decoded at a fraction of its size.
            $imagick->pingImage($sourcePath);
            $this->sourceSizeValidator->assertPingedFits($imagick, $sourcePath);
            $shrink = $this->shrinkOnLoad($imagick, $width, $height);
            $imagick->clear();

            if (null !== $shrink) {
                $imagick->setOption('jpeg:size', $shrink['hint']);
            }

            // "[0]": the first frame only. readImage() would decode every frame of a GIF/APNG/animated WebP and leave
            // the iterator on the last one; the variant is a single frame anyway, and the first one is the poster.
            $imagick->readImage($sourcePath.'[0]');
            // Before the fit: thumbnailImage() drops the EXIF block this reads.
            ColorProfile::tagFromExif($imagick);

            // EXIF rotation is applied after the fit, on the small output rather than the decoded source
            // (~136 ms per variant for a 24 MP non-JPEG source). Until then the pixels are in the stored
            // orientation, so a quarter turn swaps the fit box; origWidth/origHeight stay displayed sizes.
            $orientation = $imagick->getImageOrientation();
            $quarterTurn = ExifOrientation::isQuarterTurn($imagick);

            // With shrink-on-load the decoded size is a DCT fraction of the source; keep the real size
            // so proportional heights match ImageMetadataReader (and its <img height>) to the pixel.
            [$origWidth, $origHeight] = match (true) {
                null !== $shrink => [$shrink['width'], $shrink['height']],
                $quarterTurn => [$imagick->getImageHeight(), $imagick->getImageWidth()],
                default => [$imagick->getImageWidth(), $imagick->getImageHeight()],
            };
            $targetHeight = $height ?? $this->proportionalHeight($origWidth, $origHeight, $width);

            [$fitWidth, $fitHeight] = $quarterTurn ? [$targetHeight, $width] : [$width, $targetHeight];
            [$storedWidth, $storedHeight] = $quarterTurn ? [$origHeight, $origWidth] : [$origWidth, $origHeight];

            match ($fit) {
                'cover' => $this->fitCover($imagick, $fitWidth, $fitHeight),
                'contain' => $this->fitContain($imagick, $fitWidth, $fitHeight),
                'scale-down' => $this->fitScaleDown($imagick, $fitWidth, $fitHeight, $storedWidth, $storedHeight),
                default => $this->fitExact($imagick, $fitWidth, $fitHeight),
            };

            ExifOrientation::applyOrientation($imagick, $orientation);

            // After the fit (small output), before the watermark (composited in sRGB) and before stripImage()
            // drops the embedded profile. See ColorProfile for why transformImageColorspace() alone is not enough.
            ColorProfile::normalizeToSrgb($imagick, $this->logger);

            if (null !== $watermark) {
                $this->applyWatermark($imagick, $watermark);
            }

            $imagick->stripImage();

            $imagickFormat = self::FORMAT_MAP[$format] ?? throw new \InvalidArgumentException(\sprintf('Unsupported format: %s', $format));

            if ('JPEG' === $imagickFormat) {
                $this->flattenAlpha($imagick);
            }

            $imagick->setImageFormat($imagickFormat);
            // Two quality setters exist and feed different code paths. The HEIC/AVIF delegate
            // reads quality from the ImageInfo struct (populated by setCompressionQuality(),
            // equivalent to the CLI `-quality`), NOT from the per-image value — so AVIF silently
            // ignores setImageCompressionQuality() and every variant comes out at the encoder
            // default. WebP/JPEG honor the per-image setter. Set both so all delegates respond.
            $imagick->setCompressionQuality($quality);
            $imagick->setImageCompressionQuality($quality);

            if ($lossless) {
                $this->applyLossless($imagick, $imagickFormat);
            }

            // The cache storage owns directory creation and permissions: $outputPath is a tmp file inside
            // the target directory, renamed and chmod()ed by CacheStorageInterface::writeLocked().
            $imagick->writeImage($outputPath);
        } finally {
            $imagick->clear();
        }
    }

    /**
     * Shrink-on-load for JPEG, the dominant cost of a cache miss. When the source is at least twice the
     * output box in both dimensions, libjpeg is asked to decode at that size and scales by 1/2, 1/4 or
     * 1/8 during the IDCT (24 MP → 800 px: ~80 ms / 70 MB instead of ~415 ms / 210 MB). The hint is
     * exactly 2× the box: ImageMagick rounds the scale factor, so the decoded image is still ≥ 1.5× the
     * box (enough for Lanczos) and never larger than the source, where libjpeg would upscale instead.
     * The hint applies before autorotation, i.e. in the stored orientation. Other formats have no
     * shrink-on-load and decode fully.
     *
     * @return array{hint: string, width: int, height: int}|null the hint plus the displayed source size
     *
     * @throws \ImagickException
     */
    private function shrinkOnLoad(\Imagick $pinged, int $width, ?int $height): ?array
    {
        if ('JPEG' !== $pinged->getImageFormat()) {
            return null;
        }

        $storedWidth = $pinged->getImageWidth();
        $storedHeight = $pinged->getImageHeight();
        $quarterTurn = ExifOrientation::isQuarterTurn($pinged);
        [$displayedWidth, $displayedHeight] = $quarterTurn ? [$storedHeight, $storedWidth] : [$storedWidth, $storedHeight];

        $boxHeight = $height ?? $this->proportionalHeight($displayedWidth, $displayedHeight, $width);
        [$hintWidth, $hintHeight] = $quarterTurn ? [2 * $boxHeight, 2 * $width] : [2 * $width, 2 * $boxHeight];

        if ($storedWidth < $hintWidth || $storedHeight < $hintHeight) {
            return null;
        }

        return ['hint' => $hintWidth.'x'.$hintHeight, 'width' => $displayedWidth, 'height' => $displayedHeight];
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
            // A small output with a small percentage rounds to 0 px, and thumbnailImage(0, 0) throws "Invalid image
            // geometry" — a 500 for every such variant. One pixel is what the watermark then honestly is.
            $targetWidth = max(1, (int) round($imgWidth * $options->size / 100));
            $watermark->thumbnailImage($targetWidth, 0);
            // A watermark exported in Display P3 or Adobe RGB is composited onto an sRGB image: convert it too.
            ColorProfile::normalizeToSrgb($watermark, $this->logger);

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
     * Enable the lossless encoder branch of the format's codec. Silently ignored for
     * formats with no lossless mode (JPEG) or that are always lossless (PNG).
     */
    private function applyLossless(\Imagick $imagick, string $imagickFormat): void
    {
        match ($imagickFormat) {
            'WEBP' => $imagick->setOption('webp:lossless', 'true'),
            // AVIF files are written via ImageMagick's HEIF coder. libheif's lossless option
            // flips the AV1 encoder into true lossless mode where the build supports it
            // (libheif-plugin-aomenc), but on builds without it the flag is a silent no-op and
            // the output stays at the lossy default. Force quality to 100 as a guaranteed
            // "visually lossless" floor so lossless=true never degrades to a small lossy file.
            'AVIF' => (static function () use ($imagick): void {
                $imagick->setOption('heic:lossless', 'true');
                $imagick->setCompressionQuality(100);
                $imagick->setImageCompressionQuality(100);
            })(),
            default => null,
        };
    }
}
