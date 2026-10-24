<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

/**
 * Canonical form of a source path.
 *
 * "@default/photo.jpg" and "photo.jpg" are the same image. Entry points (component, image_url(),
 * ImageUrlGenerator, image:purge) strip the "@default/" prefix before the path reaches the URL
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
        // ImageUrlGenerator) normalize twice, and "@default/@default/x.jpg" must mean the same file everywhere.
        while (str_starts_with($src, self::DEFAULT_PREFIX)) {
            $src = substr($src, \strlen(self::DEFAULT_PREFIX));
        }

        return $src;
    }
}
