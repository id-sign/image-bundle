<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Cache;

/**
 * The files below the cache directory that the bundle wrote — the only ones image:purge and deleteBySource() remove.
 *
 * The default cache.path is the document root plus route_prefix, so it can be a directory the application uses as
 * well (route_prefix: /images over public/images). Variants, meta.json, blur.txt and leftover staging files are told
 * apart by name. An SVG passthrough copy has the name of any static SVG, so the controller puts a marker next to it.
 */
final class CacheFiles
{
    /** Looser than CachePathResolver::parse() on purpose: stray variants with leading zeros must be purgeable. */
    private const VARIANT = '/^[a-f0-9]{16}_\d+_(?:auto|\d+)_(?:none|cover|contain|scale-down)_\d+(?:_lossless)?(?:_wm-[A-Za-z0-9_-]+)?\.(?:avif|webp|jpeg|png)$/D';
    private const STAGING = '/^\.[a-f0-9]{16}\.tmp$/D';
    private const SVG_MARKER_SUFFIX = '.id-sign-image';

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
        $name = basename($absolutePath);

        return str_starts_with($name, '.') && str_ends_with($name, self::SVG_MARKER_SUFFIX);
    }

    public static function isOwned(string $absolutePath): bool
    {
        $name = basename($absolutePath);

        if (1 === preg_match(self::VARIANT, $name) || 1 === preg_match(self::STAGING, $name) || self::isSvgMarker($absolutePath)) {
            return true;
        }

        // meta.json and blur.txt live in a variants directory, which carries the source file's name.
        if ('meta.json' === $name || 'blur.txt' === $name) {
            return str_contains(basename(\dirname($absolutePath)), '.');
        }

        return str_ends_with(strtolower($name), '.svg') && is_file(self::svgMarker($absolutePath));
    }
}
