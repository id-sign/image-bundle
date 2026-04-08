<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\DependencyInjection;

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

        $container->setParameter('id_sign_image.device_sizes', $config['device_sizes']);
        $container->setParameter('id_sign_image.default_quality', $config['default_quality']);
        $container->setParameter('id_sign_image.formats', $config['formats']);
        $container->setParameter('id_sign_image.cache.ttl', $config['cache']['ttl']);
        $container->setParameter('id_sign_image.cache.path', $config['cache']['path']);
        $container->setParameter('id_sign_image.blur.enabled', $config['blur']['enabled']);
        $container->setParameter('id_sign_image.blur.size', $config['blur']['size']);
        $container->setParameter('id_sign_image.blur.quality', $config['blur']['quality']);
        $container->setParameter('id_sign_image.default_watermark', $config['default_watermark']);
        $container->setParameter('id_sign_image.watermarks', $config['watermarks']);
        $container->setParameter('id_sign_image.file_permissions', $config['file_permissions']);
        $container->setParameter('id_sign_image.directory_permissions', $config['directory_permissions']);
        $container->setParameter('id_sign_image.tmp_dir', $config['tmp_dir'] ?? sys_get_temp_dir());
        $container->setParameter('id_sign_image.auto_dimensions', $config['auto_dimensions']);
        $container->setParameter('id_sign_image.serve_mode', $config['serve_mode']);
        $container->setParameter('id_sign_image.route_prefix', $config['route_prefix']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
    }
}
