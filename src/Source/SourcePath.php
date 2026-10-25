<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

/**
 * Canonical form of a source path.
 *
 * "@default/photo.jpg", "/photo.jpg" and "photo.jpg" are the same image. Entry points (component, image_url(),
 * ImageUrlGenerator, image:purge) strip a leading slash and the "@default/" prefix before the path reaches the URL
 * signer, the cache path or the metadata caches, so one file has exactly one URL and one cache
 * directory. NamedImageSource deliberately does not know "@default/" — see there.
 */
class SourcePath
{
    public const DEFAULT_SOURCE = 'default';

    public const DEFAULT_PREFIX = '@'.self::DEFAULT_SOURCE.'/';

    public static function normalize(string $src): string
    {
        // A loop keeps the function idempotent: entry points that call each other (image_url() →
        // ImageUrlGenerator) normalize twice, and "@default/@default/x.jpg" or "/@default//x.jpg" must mean the same
        // file everywhere. The leading slash goes too: the source and the cache storage ignore it, but it would stay
        // in the signed payload and produce "/_image//x.jpg/…" — nginx merges the slashes (merge_slashes on by
        // default), the signature no longer matches and every request answers 403, while the dev server works.
        do {
            $previous = $src;
            $src = ltrim($src, '/');
            if (str_starts_with($src, self::DEFAULT_PREFIX)) {
                $src = substr($src, \strlen(self::DEFAULT_PREFIX));
            }
        } while ($previous !== $src);

        return $src;
    }

    /**
     * SVG sources are served as-is under route_prefix — no variants, no signature. One rule for the component,
     * image_url(), ImageUrlGenerator and the controller, so a signed raster URL for an .svg is never produced and
     * never processed.
     */
    public static function isSvg(string $src): bool
    {
        return 'svg' === strtolower(pathinfo($src, \PATHINFO_EXTENSION));
    }
}
