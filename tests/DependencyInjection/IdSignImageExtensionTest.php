<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\DependencyInjection;

use IdSign\ImageBundle\DependencyInjection\IdSignImageExtension;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Source\NamedImageSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

class IdSignImageExtensionTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/id_sign_image_ext_test_'.uniqid();
        mkdir($this->projectDir.'/data/uploads', 0o775, true);
        mkdir($this->projectDir.'/assets/reference', 0o775, true);
        copy(__DIR__.'/../Fixtures/test.jpg', $this->projectDir.'/data/uploads/photo.jpg');
        copy(__DIR__.'/../Fixtures/test.jpg', $this->projectDir.'/assets/reference/logo.jpg');
    }

    protected function tearDown(): void
    {
        unlink($this->projectDir.'/data/uploads/photo.jpg');
        unlink($this->projectDir.'/assets/reference/logo.jpg');
        rmdir($this->projectDir.'/data/uploads');
        rmdir($this->projectDir.'/data');
        rmdir($this->projectDir.'/assets/reference');
        rmdir($this->projectDir.'/assets');
        rmdir($this->projectDir);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.secret', 'test-secret');
        $container->register(RequestStack::class);

        (new IdSignImageExtension())->load([$config], $container);

        return $container;
    }

    public function testWithoutSourcesConfigDefaultSourceKeepsTodaysPath(): void
    {
        $container = $this->load([]);

        self::assertSame('%kernel.project_dir%/data', $container->getDefinition(LocalFilesystemSource::class)->getArgument('$basePath'));
        self::assertSame([], $container->findTaggedServiceIds('id_sign_image.source'));
    }

    public function testConfiguredDefaultSourceReplacesBasePath(): void
    {
        $container = $this->load(['sources' => ['default' => '/srv/images']]);

        self::assertSame('/srv/images', $container->getDefinition(LocalFilesystemSource::class)->getArgument('$basePath'));
        self::assertFalse($container->hasDefinition('id_sign_image.source.default'), 'default must stay on the LocalFilesystemSource service id, not become a tagged copy');
    }

    public function testNamedSourceIsRegisteredAsTaggedLocalFilesystemSource(): void
    {
        $container = $this->load(['sources' => ['reference' => '%kernel.project_dir%/assets/reference']]);

        $definition = $container->getDefinition('id_sign_image.source.reference');
        self::assertSame(LocalFilesystemSource::class, $definition->getClass());
        self::assertSame('%kernel.project_dir%/assets/reference', $definition->getArgument('$basePath'));
        self::assertSame([['name' => 'reference']], $definition->getTag('id_sign_image.source'));
    }

    public function testDefaultIsAddedWhenOnlyNamedSourcesAreConfigured(): void
    {
        $container = $this->load(['sources' => ['reference' => '/srv/reference']]);

        self::assertSame('%kernel.project_dir%/data', $container->getDefinition(LocalFilesystemSource::class)->getArgument('$basePath'));
        self::assertSame(['id_sign_image.source.reference'], array_keys($container->findTaggedServiceIds('id_sign_image.source')));
    }

    public function testSourceNameWithDashIsKeptVerbatim(): void
    {
        $container = $this->load(['sources' => ['brand-assets' => '/srv/brand']]);

        self::assertTrue($container->hasDefinition('id_sign_image.source.brand-assets'));
        self::assertFalse($container->hasDefinition('id_sign_image.source.brand_assets'));
    }

    public function testInvalidSourceNameIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Source names must start with a letter');

        $this->load(['sources' => ['bad name' => '/srv/x']]);
    }

    public function testListInsteadOfMapIsRejected(): void
    {
        // A YAML list yields integer keys; without this check it would silently register a source named "0".
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Source names must start with a letter');

        $this->load(['sources' => ['/srv/x']]);
    }

    public function testEmptySourcePathIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['sources' => ['reference' => '']]);
    }

    public function testRelativeSourcePathIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Source paths must be absolute');

        $this->load(['sources' => ['reference' => 'assets/reference']]);
    }

    public function testParameterAndDriveLetterPathsCountAsAbsolute(): void
    {
        $container = $this->load(['sources' => [
            'env' => '%env(IMAGE_DIR)%',
            'win' => 'C:\\images',
        ]]);

        self::assertTrue($container->hasDefinition('id_sign_image.source.env'));
        self::assertTrue($container->hasDefinition('id_sign_image.source.win'));
    }

    public function testCompiledContainerWiresNamedSourceBehindTheInterfaceAlias(): void
    {
        $container = $this->load(['sources' => ['reference' => '%kernel.project_dir%/assets/reference']]);
        $container->getAlias(ImageSourceInterface::class)->setPublic(true);
        $container->compile(true);

        $source = $container->get(ImageSourceInterface::class);

        self::assertInstanceOf(NamedImageSource::class, $source);
        self::assertTrue($source->exists('uploads/photo.jpg'));
        self::assertTrue($source->exists('@reference/logo.jpg'));
        self::assertFalse($source->exists('@default/uploads/photo.jpg'));
        self::assertFalse($source->exists('@unknown/logo.jpg'));
        self::assertSame($this->projectDir.'/assets/reference/logo.jpg', $source->getAbsolutePath('@reference/logo.jpg'));
    }

    public function testApplicationOverrideOfDefaultAliasIsHonoured(): void
    {
        $container = $this->load([]);
        $container->register('app.custom_source', LocalFilesystemSource::class)
            ->setArgument('$basePath', $this->projectDir.'/assets/reference');
        $container->setAlias('id_sign_image.source.default', 'app.custom_source');
        $container->getAlias(ImageSourceInterface::class)->setPublic(true);
        $container->compile(true);

        $source = $container->get(ImageSourceInterface::class);

        self::assertInstanceOf(ImageSourceInterface::class, $source);
        self::assertTrue($source->exists('logo.jpg'));
        self::assertFalse($source->exists('uploads/photo.jpg'));
    }
}
