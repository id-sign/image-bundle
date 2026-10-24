<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

/**
 * Thrown when NamedImageSource cannot resolve a "@name/path" reference: the source is not configured,
 * or the path still carries the "@default/" prefix that the bundle's entry points strip.
 */
class UnknownImageSourceException extends \InvalidArgumentException
{
    public static function forUnknownName(string $path): self
    {
        return new self(\sprintf('Unknown image source in "%s". Named sources are configured under id_sign_image.sources or registered as services tagged "id_sign_image.source" with a "name" attribute.', $path));
    }

    public static function forDefaultPrefix(string $path): self
    {
        return new self(\sprintf('"%s" still carries the "%s" prefix. The default source has no prefix; the bundle strips it at its entry points, so pass the path through SourcePath::normalize() before resolving it yourself.', $path, SourcePath::DEFAULT_PREFIX));
    }
}
