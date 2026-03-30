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
        'gif' => 'gif',
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
     * Get the MIME type for a given format.
     */
    public static function getMimeType(string $format): string
    {
        return match ($format) {
            'avif' => 'image/avif',
            'webp' => 'image/webp',
            'jpeg', 'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            default => 'application/octet-stream',
        };
    }
}
