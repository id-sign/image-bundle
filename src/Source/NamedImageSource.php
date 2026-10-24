<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Source;

use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Dispatches "@name/path" source references to named sources; paths without a prefix go to the default source.
 *
 * The prefix stays part of the path everywhere else (URL, HMAC payload, cache directory), so
 * variants of "@reference/logo.png" and "logo.png" never share a cache entry. Only this class
 * knows how to turn the prefix into a filesystem location.
 */
class NamedImageSource implements ImageSourceInterface
{
    /**
     * @param ServiceProviderInterface<ImageSourceInterface> $named sources tagged "id_sign_image.source", indexed by their "name" attribute
     */
    public function __construct(
        private readonly ImageSourceInterface $default,
        private readonly ServiceProviderInterface $named,
    ) {
    }

    public function exists(string $path): bool
    {
        try {
            [$source, $relative] = $this->resolve($path);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return null !== $source && $source->exists($relative);
    }

    public function getAbsolutePath(string $path): string
    {
        [$source, $relative] = $this->resolve($path);

        if (null === $source) {
            if (str_starts_with($path, SourcePath::DEFAULT_PREFIX)) {
                throw UnknownImageSourceException::forDefaultPrefix($path);
            }

            throw UnknownImageSourceException::forUnknownName($path);
        }

        return $source->getAbsolutePath($relative);
    }

    /**
     * Split "@name/relative" into the named source and the path inside it.
     *
     * "@default/" is rejected on purpose: SourcePath::normalize() strips it at every entry point,
     * so a path that still carries it was never produced by the bundle and must not create a second
     * URL or cache directory for a file the default source already serves without the prefix.
     *
     * @return array{ImageSourceInterface|null, string}
     *
     * @throws \InvalidArgumentException when nothing or a further slash follows "@name/": the cache layer
     *                                   rejects that empty segment, so the source must not report a file
     *                                   there (LocalFilesystemSource alone would trim the slash away and
     *                                   the controller would answer 500 instead of 404)
     */
    private function resolve(string $path): array
    {
        if (!str_starts_with($path, '@')) {
            return [$this->default, $path];
        }

        $slash = strpos($path, '/');
        if (false === $slash) {
            return [null, $path];
        }

        $name = substr($path, 1, $slash - 1);
        if (SourcePath::DEFAULT_SOURCE === $name || !$this->named->has($name)) {
            return [null, $path];
        }

        $relative = substr($path, $slash + 1);
        if ('' === $relative || str_starts_with($relative, '/')) {
            throw new \InvalidArgumentException(\sprintf('Invalid path segment (empty) in: %s', $path));
        }

        return [$this->named->get($name), $relative];
    }
}
