<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Service;

use Symfony\Component\HttpFoundation\Request;

class FormatNegotiator
{
    private const MIME_MAP = [
        'avif' => 'image/avif',
        'webp' => 'image/webp',
    ];

    /** Map source extensions to web-safe fallback formats. */
    private const EXTENSION_TO_FORMAT = [
        'jpg' => 'jpeg',
        'jpeg' => 'jpeg',
        'png' => 'png',
        'gif' => 'jpeg',
        'tiff' => 'jpeg',
        'tif' => 'jpeg',
        'heic' => 'jpeg',
        'heif' => 'jpeg',
        'bmp' => 'jpeg',
        'avif' => 'avif',
        'webp' => 'webp',
    ];

    /**
     * @param list<string> $configuredFormats Formats in priority order (e.g. ['avif', 'webp'])
     */
    public function __construct(
        private readonly array $configuredFormats,
    ) {
    }

    /**
     * Negotiate the best output format based on Accept header and source file extension.
     */
    public function negotiate(string $acceptHeader, string $sourceExtension): string
    {
        foreach ($this->configuredFormats as $format) {
            $mime = self::MIME_MAP[$format] ?? null;
            if (null !== $mime && str_contains($acceptHeader, $mime)) {
                return $format;
            }
        }

        return self::getFallbackFormat($sourceExtension);
    }

    /**
     * Whether the application would generate this format for this source: one of the configured formats, or the
     * source's fallback. The signature does not cover the format (one HMAC serves every <source> of a width), so
     * without this check a single signed URL would yield a variant per accepted extension — and PNG is lossless,
     * a 3840 px photo as PNG is 20–30 MB on disk plus the encode.
     */
    public function isOffered(string $format, string $src): bool
    {
        return \in_array($format, $this->configuredFormats, true)
            || $format === self::getFallbackFormat(pathinfo($src, \PATHINFO_EXTENSION));
    }

    /**
     * Get the web-safe fallback format for a source file extension.
     */
    public static function getFallbackFormat(string $sourceExtension): string
    {
        return self::EXTENSION_TO_FORMAT[strtolower($sourceExtension)] ?? 'jpeg';
    }

    /**
     * Negotiate the best output format from a Request object and source image path.
     */
    public function negotiateFromRequest(Request $request, string $src): string
    {
        return $this->negotiate(
            $request->headers->get('Accept', ''),
            pathinfo($src, \PATHINFO_EXTENSION),
        );
    }

    /**
     * Get the MIME type for a given output format — one of the four variant extensions CachePathResolver::parse()
     * accepts. "jpg" is never an output format: the bundle writes "jpeg" (see the parse pattern).
     */
    public static function getMimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
