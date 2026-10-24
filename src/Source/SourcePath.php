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

    private const DEFAULT_PREFIX = '@'.self::DEFAULT_SOURCE.'/';

    public static function normalize(string $src): string
    {
        return str_starts_with($src, self::DEFAULT_PREFIX) ? substr($src, \strlen(self::DEFAULT_PREFIX)) : $src;
    }
}
