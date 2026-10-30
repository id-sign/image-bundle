<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\DependencyInjection;

use IdSign\ImageBundle\Service\ColorProfile;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Source\SourcePath;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class IdSignImageExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('twig_component', [
            'defaults' => [
                'IdSign\\ImageBundle\\Twig\\Component\\' => '@IdSignImage/components/',
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // Fail at container build, not at the first cache miss on a profiled source: a deployment that ships the
        // package without resources/ (export-ignore, vendor pruning) would otherwise answer 500 for iPhone photos.
        foreach ([ColorProfile::SRGB_PROFILE, ColorProfile::ADOBE_RGB_PROFILE] as $profile) {
            if (!is_readable($profile)) {
                throw new \RuntimeException(\sprintf('The bundled ICC profile "%s" is missing or unreadable; install id-sign/image-bundle with its resources/ directory.', $profile));
            }
        }

        $container->setParameter('id_sign_image.device_sizes', $config['device_sizes']);
        $container->setParameter('id_sign_image.default_quality', $config['default_quality']);
        $container->setParameter('id_sign_image.format_quality', $config['format_quality']);
        $container->setParameter('id_sign_image.formats', $config['formats']);
        $container->setParameter('id_sign_image.cache.ttl', $config['cache']['ttl']);
        // Public mode serves the URL path from the document root, so the cache directory has to follow route_prefix.
        $container->setParameter('id_sign_image.cache.path', $config['cache']['path'] ?? '%kernel.project_dir%/public'.$config['route_prefix']);
        $container->setParameter('id_sign_image.blur.enabled', $config['blur']['enabled']);
        $container->setParameter('id_sign_image.blur.size', $config['blur']['size']);
        $container->setParameter('id_sign_image.blur.quality', $config['blur']['quality']);
        $container->setParameter('id_sign_image.default_watermark', $config['default_watermark']);
        $container->setParameter('id_sign_image.watermarks', $config['watermarks']);
        $container->setParameter('id_sign_image.file_permissions', $config['file_permissions']);
        $container->setParameter('id_sign_image.directory_permissions', $config['directory_permissions']);
        $container->setParameter('id_sign_image.auto_dimensions', $config['auto_dimensions']);
        $container->setParameter('id_sign_image.lossless', $config['lossless']);
        $container->setParameter('id_sign_image.max_width', $config['max_width']);
        $container->setParameter('id_sign_image.max_source_bytes', $config['max_source_bytes']);
        $container->setParameter('id_sign_image.max_source_pixels', $config['max_source_pixels']);
        $container->setParameter('id_sign_image.serve_mode', $config['serve_mode']);
        $container->setParameter('id_sign_image.route_prefix', $config['route_prefix']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');

        /** @var array<string, string> $sources always contains "default" — Configuration adds it when missing */
        $sources = $config['sources'];
        $this->registerSources($container, $sources);
    }

    /**
     * The default source stays on the LocalFilesystemSource service id so an application-level
     * override of its $basePath keeps working; every other source becomes its own tagged
     * LocalFilesystemSource, collected by NamedImageSource through the "id_sign_image.source" tag.
     *
     * @param array<string, string> $sources
     */
    private function registerSources(ContainerBuilder $container, array $sources): void
    {
        $container->getDefinition(LocalFilesystemSource::class)
            ->setArgument('$basePath', $sources[SourcePath::DEFAULT_SOURCE]);

        foreach ($sources as $name => $basePath) {
            if (SourcePath::DEFAULT_SOURCE === $name) {
                continue;
            }

            $container->register('id_sign_image.source.'.$name, LocalFilesystemSource::class)
                ->setArgument('$basePath', $basePath)
                ->addTag('id_sign_image.source', ['name' => $name]);
        }
    }
}
