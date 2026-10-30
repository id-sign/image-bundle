<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\DependencyInjection;

use IdSign\ImageBundle\Service\FormatNegotiator;
use IdSign\ImageBundle\Source\SourcePath;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * Base path of the "default" source when the application does not configure one.
     */
    public const DEFAULT_SOURCE_PATH = '%kernel.project_dir%/data';

    /**
     * Structure, types and the checks that hold for an %env()% value too. Ranges, paths, route_prefix and
     * default_watermark are checked in IdSignImageExtension::validateValues(): Symfony validates an env value at build
     * time with a dummy (0, ''), so a min(1) or a path check here fails every application that sets the option from
     * the environment.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('id_sign_image');

        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('device_sizes')
                    ->info('Breakpoints for the srcset, in pixels. A later config file replaces the list.')
                    // Appending across config files (see formats) duplicated srcset candidates.
                    ->performNoDeepMerging()
                    ->integerPrototype()->end()
                    ->defaultValue([320, 480, 640, 750, 828, 1080, 1200, 1920, 2048, 3840])
                ->end()
                ->integerNode('default_quality')
                    ->defaultValue(80)
                    ->info('Output quality 1-100.')
                ->end()
                ->arrayNode('format_quality')
                    ->info('Output quality per format, e.g. {avif: 55, webp: 75}. Formats not listed use default_quality.')
                    ->useAttributeAsKey('format')
                    ->integerPrototype()->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => [] !== array_diff(array_map('strval', array_keys($v)), FormatNegotiator::OUTPUT_FORMATS))
                        ->thenInvalid('format_quality keys must be output formats ('.implode(', ', FormatNegotiator::OUTPUT_FORMATS).') — the bundle writes "jpeg", never "jpg" (got %s).')
                    ->end()
                ->end()
                ->arrayNode('sources')
                    ->info('Named image sources: name => absolute base path. Reference them as "@name/path" in src. Paths without a prefix use the "default" source (added automatically when omitted).')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->defaultValue([SourcePath::DEFAULT_SOURCE => self::DEFAULT_SOURCE_PATH])
                    ->scalarPrototype()->end()
                    ->validate()
                        // Adds the default source when only named ones are configured. Validation runs on the
                        // merged value of all config files; a beforeNormalization() default would let a file
                        // that only adds named sources (e.g. a when@prod block) reset a "default" from the base file.
                        ->always(static fn (array $v): array => $v + [SourcePath::DEFAULT_SOURCE => self::DEFAULT_SOURCE_PATH])
                    ->end()
                    ->validate()
                        // A leading letter also rejects the integer keys of a list written where a map was expected.
                        ->ifTrue(static fn (array $v): bool => [] !== preg_grep('/^[A-Za-z][A-Za-z0-9_-]*$/D', array_map('strval', array_keys($v)), \PREG_GREP_INVERT))
                        ->thenInvalid('Source names must start with a letter and may only contain letters, digits, "_" and "-" (got %s).')
                    ->end()
                ->end()
                ->arrayNode('formats')
                    ->info('Output formats offered as <source> elements, in priority order. A later config file replaces the list.')
                    // Prototyped lists append across config files: a when@prod block with [webp] after a base
                    // [avif, webp] gave [avif, webp, webp] — a duplicate <source>, and no way to drop a format.
                    ->performNoDeepMerging()
                    ->enumPrototype()
                        ->values(FormatNegotiator::OUTPUT_FORMATS)
                    ->end()
                    ->defaultValue(['avif', 'webp'])
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => \count($v) !== \count(array_unique($v)))
                        ->thenInvalid('formats must not list a format twice (got %s).')
                    ->end()
                ->end()
                ->arrayNode('cache')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('ttl')
                            ->defaultValue(2592000)
                            ->min(0)
                            ->info('Seconds a cached variant stays fresh in controller mode. 0 re-encodes on every request (debugging only; responses stay immutable for browsers).')
                        ->end()
                        ->scalarNode('path')
                            ->defaultNull()
                            ->info('Cache directory. Default: %kernel.project_dir%/public + route_prefix, where the web server finds the files in public mode.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('blur')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultFalse()
                        ->end()
                        ->integerNode('size')
                            ->defaultValue(10)
                            ->info('Placeholder width in pixels, 1-64.')
                        ->end()
                        ->integerNode('quality')
                            ->defaultValue(30)
                            ->info('JPEG quality of the placeholder, 1-100.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('default_watermark')
                    ->defaultNull()
                    ->info('Default watermark profile name applied to all images (null = no watermark)')
                ->end()
                ->arrayNode('watermarks')
                    ->useAttributeAsKey('name')
                    // The name ends up in URLs (_wm-{name}) and in the watermark prop. Key normalization turned
                    // "brand-logo" into "brand_logo" while default_watermark and the prop kept the dash: every URL 400.
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->scalarNode('path')
                                ->isRequired()
                                ->info('Absolute path to the watermark image.')
                            ->end()
                            ->enumNode('position')
                                ->values(['top-left', 'top-center', 'top-right', 'center-left', 'center', 'center-right', 'bottom-left', 'bottom-center', 'bottom-right'])
                                ->defaultValue('bottom-right')
                            ->end()
                            ->integerNode('opacity')
                                ->defaultValue(50)
                                ->min(0)->max(100)
                            ->end()
                            ->integerNode('size')
                                ->defaultValue(20)
                                ->info('Watermark width as percentage of output image width, 1-100')
                            ->end()
                            ->integerNode('margin')
                                ->defaultValue(10)
                                ->min(0)
                                ->info('Margin from edge in pixels')
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        // CachePathResolver::parse() accepts only these characters after "_wm-".
                        ->ifTrue(static fn (array $v): bool => [] !== preg_grep('/^[A-Za-z0-9_-]+$/D', array_map('strval', array_keys($v)), \PREG_GREP_INVERT))
                        ->thenInvalid('Watermark profile names may only contain letters, digits, "_" and "-" (got %s).')
                    ->end()
                ->end()
                ->booleanNode('auto_dimensions')
                    ->defaultFalse()
                ->end()
                ->booleanNode('lossless')
                    ->defaultFalse()
                    ->info('Global default for lossless encoding. Applies to WebP and AVIF outputs; silently ignored for JPEG (no lossless mode) and PNG (already lossless). AVIF lossless requires libheif with AV1 encoder plugin (libheif-plugin-aomenc on Debian/Ubuntu).')
                ->end()
                ->integerNode('max_width')
                    ->defaultValue(4096)
                    ->info('Maximum accepted output width in pixels. Protects against memory exhaustion from accidentally large values.')
                ->end()
                ->integerNode('max_source_bytes')
                    ->defaultValue(20_971_520) // 20 MiB
                    ->min(0)
                    ->info('Maximum source file size in bytes. Protects against decompression bombs and large accidental uploads. 0 disables the check.')
                ->end()
                ->integerNode('max_source_pixels')
                    ->defaultValue(64_000_000) // 64 MP: 48 MP phones and 61 MP cameras pass, 100 MP medium format needs a higher value
                    ->min(0)
                    ->info('Maximum source image area in pixels (width × height), read from the header before decoding. Catches decompression bombs that pass max_source_bytes: a 100 MP JPEG is ~15 MB but needs over 1 GB to decode. 0 disables the check.')
                ->end()
                ->variableNode('file_permissions')
                    ->defaultValue(0o660)
                    ->info('Permissions for created cache files, e.g. 0o660 (YAML reads 0660 as a string). Null = umask default.')
                ->end()
                ->integerNode('directory_permissions')
                    ->defaultValue(0o770)
                    ->info('Permissions for created cache directories, e.g. 0o770 (YAML reads 0770 as a string).')
                ->end()
                ->enumNode('serve_mode')
                    ->values(['controller', 'public'])
                    ->defaultValue('public')
                ->end()
                ->scalarNode('route_prefix')
                    ->defaultValue('/_image')
                    ->info('URL prefix of every image URL, the path of the bundle route and (by default) of the cache directory.')
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
