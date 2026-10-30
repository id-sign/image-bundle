<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

use IdSign\ImageBundle\Service\UrlSigner;

class CachePathResolver
{
    public function __construct(
        private readonly UrlSigner $urlSigner,
    ) {
    }

    /**
     * Build a path that serves as both URL (after route prefix) and filesystem cache path.
     *
     * Format: {src}/{signature}_{w}_{h}_{fit}_{q}[_lossless][_wm-{profile}].{format}
     */
    public function resolve(
        string $src,
        int $width,
        ?int $height,
        ?string $fit,
        int $quality,
        string $format,
        ?string $watermark = null,
        bool $lossless = false,
    ): string {
        $signature = $this->urlSigner->sign($src, $width, $height, $fit, $quality, $watermark, $lossless);
        $heightPart = $height ?? 'auto';
        $fitPart = $fit ?? 'none';
        $losslessPart = $lossless ? '_lossless' : '';
        $wmPart = null !== $watermark ? '_wm-'.$watermark : '';

        return \sprintf(
            '%s/%s_%d_%s_%s_%d%s%s.%s',
            $src,
            $signature,
            $width,
            $heightPart,
            $fitPart,
            $quality,
            $losslessPart,
            $wmPart,
            $format,
        );
    }

    /**
     * Encode a cache path for use in a URL.
     *
     * Encodes each segment with rawurlencode so unsafe characters in source filenames
     * — most importantly spaces — don't break srcset parsing (a literal space is the
     * separator between URL and width descriptor, so an unencoded space makes the
     * browser discard the entire <source>) or HTML attribute boundaries. Slashes are
     * preserved as path separators.
     */
    public static function encodeForUrl(string $path): string
    {
        // One C-level pass over the string; only the slashes rawurlencode() turned into %2F come back.
        return str_replace('%2F', '/', rawurlencode($path));
    }

    /**
     * Parse image parameters from a path (URL path after route prefix).
     *
     * The pattern is anchored with the D modifier: a bare "$" also matches before a trailing newline, and
     * the route's ".+" requirement lets "%0A" through — a known signed URL plus "%0A" would then parse and
     * verify like the original and produce a second variant file with "\n" in its name. Numbers have no leading
     * zero for the same reason: the signature covers the parsed integers, so "_0800_" verified like "_800_" and every
     * extra zero was another encode and another file from one public URL.
     *
     * @return array{src: string, signature: string, width: int, height: ?int, fit: ?string, quality: int, lossless: bool, watermark: ?string, format: string}|null
     */
    public function parse(string $path): ?array
    {
        if (!preg_match('#^(.+)/([a-f0-9]{16})_([1-9]\d*)_(auto|[1-9]\d*)_(none|cover|contain|scale-down)_([1-9]\d*)(_lossless)?(?:_wm-([a-zA-Z0-9_-]+))?\.(avif|webp|jpeg|png)$#D', $path, $matches)) {
            return null;
        }

        return [
            'src' => $matches[1],
            'signature' => $matches[2],
            'width' => (int) $matches[3],
            'height' => 'auto' === $matches[4] ? null : (int) $matches[4],
            'fit' => 'none' === $matches[5] ? null : $matches[5],
            'quality' => (int) $matches[6],
            'lossless' => '_lossless' === $matches[7],
            'watermark' => '' !== $matches[8] ? $matches[8] : null,
            'format' => $matches[9],
        ];
    }
}
