<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The value checks Configuration cannot do. Symfony validates an %env()% value at build time with a dummy — 0 for an
 * int: variable, '' otherwise — so a min(1) or a path check in the tree fails every application that sets the option
 * from the environment. Here an env value is recognisable and skipped: it is resolved at runtime, unchecked, as it
 * always was.
 */
final class ConfigValueValidator
{
    /**
     * "/…", "%param%…" or a Windows drive. A relative path resolves against the current working directory, which
     * differs between the web server (public/) and the console (project root).
     */
    private const ABSOLUTE_PATH = '#^(/|%|[A-Za-z]:[\\\\/])#';

    /**
     * URL-safe segments, none of them "." or "..": browsers drop those from URLs, and the default cache.path — the
     * document root plus route_prefix — would climb out of the document root.
     */
    private const ROUTE_PREFIX = '#^(?:/(?!\.{1,2}(?:/|$))[A-Za-z0-9._~-]+)+$#D';

    private function __construct(
        private readonly ContainerBuilder $container,
    ) {
    }

    /**
     * @param array<mixed> $config the processed bundle configuration
     *
     * @throws InvalidConfigurationException
     */
    public static function validate(array $config, ContainerBuilder $container): void
    {
        $validator = new self($container);

        $deviceSizes = self::list($config, 'device_sizes');
        foreach ($deviceSizes as $i => $size) {
            // A 0 becomes "0w", a parse error that makes browsers drop every candidate of the srcset.
            $validator->assertRange($size, 1, \PHP_INT_MAX, 'device_sizes.'.$i);
        }
        // Appending across config files used to duplicate candidates; a duplicate in one list is a mistake as well.
        if (\count($deviceSizes) !== \count(array_unique($deviceSizes, \SORT_REGULAR))) {
            throw self::invalid('device_sizes', 'device_sizes must not list a breakpoint twice');
        }

        $validator->assertRange($config['default_quality'] ?? null, 1, 100, 'default_quality');
        foreach (self::list($config, 'format_quality') as $format => $quality) {
            $validator->assertRange($quality, 1, 100, 'format_quality.'.$format);
        }

        $blur = self::list($config, 'blur');
        // Generated during the page render and inlined as a data URI into every page: 640 px is already ~19 kB.
        $validator->assertRange($blur['size'] ?? null, 1, 64, 'blur.size');
        $validator->assertRange($blur['quality'] ?? null, 1, 100, 'blur.quality');

        $validator->assertRange($config['max_width'] ?? null, 1, \PHP_INT_MAX, 'max_width');

        foreach (self::list($config, 'sources') as $name => $path) {
            $validator->assertAbsolutePath($path, 'sources.'.$name, 'Source paths must be absolute, e.g. "/srv/images" or "%kernel.project_dir%/data"');
        }

        $watermarks = self::list($config, 'watermarks');
        foreach ($watermarks as $name => $profile) {
            $profile = \is_array($profile) ? $profile : [];
            $validator->assertAbsolutePath($profile['path'] ?? null, 'watermarks.'.$name.'.path', 'Watermark paths must be absolute, e.g. "%kernel.project_dir%/data/watermark.png"');
            $validator->assertRange($profile['size'] ?? null, 1, 100, 'watermarks.'.$name.'.size');
        }

        // A profile that does not exist would put an unknown _wm-{name} into every image URL: 400 for every image.
        $defaultWatermark = $config['default_watermark'] ?? null;
        if (null !== $defaultWatermark && !$validator->isEnv($defaultWatermark) && !\array_key_exists(\is_scalar($defaultWatermark) ? (string) $defaultWatermark : '', $watermarks)) {
            throw self::invalid('default_watermark', \sprintf('"%s" is not a configured watermark profile (configured: %s)', \is_scalar($defaultWatermark) ? $defaultWatermark : get_debug_type($defaultWatermark), [] === $watermarks ? 'none' : implode(', ', array_keys($watermarks))));
        }

        $cachePath = self::list($config, 'cache')['path'] ?? null;
        if (null !== $cachePath) {
            // A relative path lands under public/ for the web server — served without the firewall — while
            // image:purge looks below the project root; '' writes to the filesystem root.
            $validator->assertAbsolutePath($cachePath, 'cache.path', 'cache.path must be absolute, e.g. "%kernel.project_dir%/var/image-cache"');
        }

        $routePrefix = $config['route_prefix'] ?? null;
        if ($validator->isEnv($routePrefix)) {
            throw self::invalid('route_prefix', 'it cannot come from an environment variable: it is the path of the bundle route and of the default cache directory, both fixed when the container is built');
        }
        if (!\is_string($routePrefix) || 1 !== preg_match(self::ROUTE_PREFIX, $routePrefix)) {
            // Without the leading slash URLs are relative to the page, a trailing one yields "//", which nginx merges.
            throw self::invalid('route_prefix', \sprintf('route_prefix must start with "/" and must not end with one; its segments may contain letters, digits and "._~-" and must not be "." or "..", e.g. "/_image" or "/media/images" (got "%s")', \is_scalar($routePrefix) ? $routePrefix : get_debug_type($routePrefix)));
        }

        // image:purge empties the cache directory: a source in it would lose its images, and raster variants could
        // not be created anyway (the variants directory photo.jpg/ collides with the source file photo.jpg).
        $validator->assertCacheOutsideSources($cachePath ?? '%kernel.project_dir%/public'.$routePrefix, self::list($config, 'sources'));

        // The owner needs read/write (files) or read/write/execute (directories), or every cache write fails.
        $validator->assertMode($config['file_permissions'] ?? null, 0o600, 'file_permissions', true);
        $validator->assertMode($config['directory_permissions'] ?? null, 0o700, 'directory_permissions', false);
    }

    private function isEnv(mixed $value): bool
    {
        $usedEnvs = [];
        $this->container->resolveEnvPlaceholders($value, null, $usedEnvs);

        return [] !== $usedEnvs;
    }

    /**
     * A source inside the cache directory (or the same directory) is refused; the cache inside a source is fine —
     * sources.default: public with the default cache public/_image only ever empties public/_image.
     *
     * @param array<mixed> $sources
     */
    private function assertCacheOutsideSources(mixed $cachePath, array $sources): void
    {
        $cache = $this->resolvePath($cachePath);
        if (null === $cache) {
            return;
        }

        foreach ($sources as $name => $sourcePath) {
            $source = $this->resolvePath($sourcePath);

            if (null !== $source && ($source === $cache || str_starts_with($source.'/', $cache.'/'))) {
                throw self::invalid('cache.path', \sprintf('the cache directory "%s" holds the source "%s" (%s) — image:purge empties the cache directory and would delete the source images; give the cache a directory of its own, e.g. cache.path: "%%kernel.project_dir%%/public/_image"', $cache, $source, 'sources.'.$name));
            }
        }
    }

    /**
     * The path with parameters resolved and slashes normalized, or null when it cannot be known at build time (an env
     * value, an unknown parameter).
     */
    private function resolvePath(mixed $path): ?string
    {
        if (!\is_string($path) || $this->isEnv($path)) {
            return null;
        }

        try {
            $resolved = $this->container->getParameterBag()->resolveValue($path);
        } catch (\Throwable) {
            return null;
        }

        return \is_string($resolved) ? rtrim((string) preg_replace('#/+#', '/', $resolved), '/') : null;
    }

    private function assertRange(mixed $value, int $min, int $max, string $path): void
    {
        // Anything but an int is an env placeholder here; Configuration already rejected other types.
        if (\is_int($value) && ($value < $min || $value > $max)) {
            throw self::invalid($path, \sprintf('%d is out of range, expected %s', $value, \PHP_INT_MAX === $max ? 'at least '.$min : $min.'-'.$max));
        }
    }

    private function assertAbsolutePath(mixed $value, string $path, string $message): void
    {
        if ($this->isEnv($value)) {
            return;
        }

        if (!\is_string($value) || 1 !== preg_match(self::ABSOLUTE_PATH, $value)) {
            throw self::invalid($path, \sprintf('%s (got "%s")', $message, \is_scalar($value) ? $value : get_debug_type($value)));
        }
    }

    private function assertMode(mixed $value, int $ownerBits, string $path, bool $nullable): void
    {
        if (($nullable && null === $value) || $this->isEnv($value)) {
            return;
        }

        $example = $ownerBits | 0o060;

        if (!\is_int($value)) {
            throw self::invalid($path, \sprintf('expected an octal mode such as 0o%o, got %s', $example, \is_string($value) ? \sprintf('the string "%s" — YAML reads 0%o as a string', $value, $example) : get_debug_type($value)));
        }

        // A negative int has every bit set: -1 passed the owner check and became 0o7777 — setuid, world-writable.
        if ($value < 0 || $value > 0o7777 || ($value & $ownerBits) !== $ownerBits) {
            throw self::invalid($path, \sprintf('mode 0o%o must not exceed 0o7777 and must give the owner at least 0o%o; write it in octal, e.g. 0o%o — a plain %o is decimal (0o%o)', $value, $ownerBits, $example, $example, (int) decoct($example)));
        }
    }

    /**
     * @param array<mixed> $config
     *
     * @return array<mixed>
     */
    private static function list(array $config, string $key): array
    {
        return \is_array($config[$key] ?? null) ? $config[$key] : [];
    }

    private static function invalid(string $path, string $message): InvalidConfigurationException
    {
        return new InvalidConfigurationException(\sprintf('Invalid configuration for path "id_sign_image.%s": %s.', $path, $message));
    }
}
