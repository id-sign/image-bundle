<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

/**
 * Thrown when a "@name/path" source reference names a source that is not configured.
 */
class UnknownImageSourceException extends \InvalidArgumentException
{
    public function __construct(string $path)
    {
        parent::__construct(\sprintf('Unknown image source in "%s". Named sources are configured under id_sign_image.sources or registered as services tagged "id_sign_image.source" with a "name" attribute.', $path));
    }
}
