<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

/**
 * The files below the cache directory that the bundle wrote — the only ones image:purge and deleteBySource() remove.
 *
 * The default cache.path is the document root plus route_prefix, so it can be a directory the application uses as
 * well (route_prefix: /images over public/images). Variants and leftover staging files are told apart by name,
 * meta.json and blur.txt by content. An SVG passthrough copy has the name of any static SVG, so the controller puts a
 * marker next to it.
 */
final class CacheFiles
{
    /** Looser than CachePathResolver::parse() on purpose: stray variants with leading zeros must be purgeable. */
    private const VARIANT = '/^[a-f0-9]{16}_\d+_(?:auto|\d+)_(?:none|cover|contain|scale-down)_\d+(?:_lossless)?(?:_wm-[A-Za-z0-9_-]+)?\.(?:avif|webp|jpeg|png)$/D';
    private const STAGING = '/^\.[a-f0-9]{16}\.tmp$/D';
    private const SVG_MARKER = '/^\..+\.svg\.id-sign-image$/Di';
    private const SVG_MARKER_SUFFIX = '.id-sign-image';
    /** What ImageMetadataReader writes. */
    private const META = '/^\{"width":\d+,"height":\d+\}$/D';
    /** How every data URI of BlurPlaceholderGenerator starts. */
    private const BLUR_PREFIX = 'data:image/jpeg;base64,';

    /**
     * Path of the marker that flags the SVG copy at $path as the bundle's; works on cache paths and absolute paths.
     */
    public static function svgMarker(string $path): string
    {
        $dir = \dirname($path);

        return ('.' === $dir ? '' : $dir.'/').'.'.basename($path).self::SVG_MARKER_SUFFIX;
    }

    /**
     * The SVG copy a marker flags — svgMarker() reversed.
     */
    public static function markedSvg(string $markerPath): string
    {
        return \dirname($markerPath).'/'.substr(basename($markerPath), 1, -\strlen(self::SVG_MARKER_SUFFIX));
    }

    public static function isSvgMarker(string $absolutePath): bool
    {
        return 1 === preg_match(self::SVG_MARKER, basename($absolutePath));
    }

    public static function isOwned(string $absolutePath): bool
    {
        $name = basename($absolutePath);

        if (1 === preg_match(self::VARIANT, $name) || 1 === preg_match(self::STAGING, $name) || self::isSvgMarker($absolutePath)) {
            return true;
        }

        // By content, not by directory: a variants directory has the source's name, which needs no extension
        // (avatars/42/), while the application's own v1.2/meta.json must stay. Read on purge only.
        if ('meta.json' === $name) {
            return 1 === preg_match(self::META, self::head($absolutePath, 64));
        }

        if ('blur.txt' === $name) {
            return str_starts_with(self::head($absolutePath, \strlen(self::BLUR_PREFIX)), self::BLUR_PREFIX);
        }

        return str_ends_with(strtolower($name), '.svg') && is_file(self::svgMarker($absolutePath));
    }

    /**
     * @param positive-int $length
     */
    private static function head(string $path, int $length): string
    {
        $handle = @fopen($path, 'r');

        if (false === $handle) {
            return '';
        }

        try {
            return (string) fread($handle, $length);
        } finally {
            fclose($handle);
        }
    }
}
