<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\DependencyInjection;

use IdSign\ImageBundle\DependencyInjection\IdSignImageExtension;
use IdSign\ImageBundle\Service\QualityResolver;
use IdSign\ImageBundle\Source\ImageSourceInterface;
use IdSign\ImageBundle\Source\LocalFilesystemSource;
use IdSign\ImageBundle\Source\NamedImageSource;
use PHPUnit\Framework\Attributes\DataProvider;
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
     * @param array<string, mixed> ...$configs one entry per config file, merged in order like the kernel does
     */
    private function load(array ...$configs): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.secret', 'test-secret');
        $container->register(RequestStack::class);

        (new IdSignImageExtension())->load($configs, $container);

        return $container;
    }

    public function testWithoutSourcesConfigDefaultSourceKeepsTodaysPath(): void
    {
        $container = $this->load([]);

        self::assertSame('%kernel.project_dir%/data', $container->getDefinition(LocalFilesystemSource::class)->getArgument('$basePath'));
        self::assertSame([], $container->findTaggedServiceIds('id_sign_image.source'));
    }

    public function testSourceLimitsDefaultTo20MiBAnd64Megapixels(): void
    {
        $container = $this->load([]);

        self::assertSame(20_971_520, $container->getParameter('id_sign_image.max_source_bytes'));
        self::assertSame(64_000_000, $container->getParameter('id_sign_image.max_source_pixels'));
    }

    public function testWatermarkNameWithDashIsKeptVerbatimAndUsableAsDefault(): void
    {
        // Key normalization made it "brand_logo" while default_watermark kept the dash: every image URL answered 400.
        $container = $this->load([
            'watermarks' => ['brand-logo' => ['path' => '/srv/brand-logo.png']],
            'default_watermark' => 'brand-logo',
        ]);

        /** @var array<string, mixed> $watermarks */
        $watermarks = $container->getParameter('id_sign_image.watermarks');
        self::assertSame(['brand-logo'], array_keys($watermarks));
        self::assertSame('brand-logo', $container->getParameter('id_sign_image.default_watermark'));
    }

    public function testDefaultWatermarkMustNameAConfiguredProfile(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"id_sign_image.default_watermark": "copyrigth" is not a configured watermark profile (configured: copyright)');

        $this->load([
            'watermarks' => ['copyright' => ['path' => '/srv/watermark.png']],
            'default_watermark' => 'copyrigth',
        ]);
    }

    public function testDefaultWatermarkCanComeFromAnotherConfigFile(): void
    {
        $container = $this->load(
            ['watermarks' => ['copyright' => ['path' => '/srv/watermark.png']]],
            ['default_watermark' => 'copyright'],
        );

        self::assertSame('copyright', $container->getParameter('id_sign_image.default_watermark'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'watermark name with a space' => [['watermarks' => ['my logo' => ['path' => '/srv/logo.png']]], 'Watermark profile names'];
        yield 'watermark name with diacritics' => [['watermarks' => ['logo-č' => ['path' => '/srv/logo.png']]], 'Watermark profile names'];
        yield 'relative watermark path' => [['watermarks' => ['copyright' => ['path' => 'data/watermark.png']]], 'Watermark paths must be absolute'];
        yield 'watermark size 0' => [['watermarks' => ['copyright' => ['path' => '/srv/w.png', 'size' => 0]]], 'size'];
        yield 'watermark size above 100' => [['watermarks' => ['copyright' => ['path' => '/srv/w.png', 'size' => 101]]], 'size'];
        yield 'negative watermark margin' => [['watermarks' => ['copyright' => ['path' => '/srv/w.png', 'margin' => -1]]], 'margin'];
        yield 'blur.size 0' => [['blur' => ['size' => 0]], 'size'];
        yield 'negative cache.ttl' => [['cache' => ['ttl' => -1]], 'ttl'];
        yield 'route_prefix without leading slash' => [['route_prefix' => '_image'], 'route_prefix must start with "/"'];
        yield 'route_prefix with trailing slash' => [['route_prefix' => '/_image/'], 'route_prefix must start with "/"'];
        yield 'route_prefix "/"' => [['route_prefix' => '/'], 'route_prefix must start with "/"'];
        yield 'route_prefix with a space' => [['route_prefix' => '/my images'], 'route_prefix must start with "/"'];
        yield 'route_prefix "/."' => [['route_prefix' => '/.'], 'must not be "." or ".."'];
        yield 'route_prefix "/.."' => [['route_prefix' => '/..'], 'must not be "." or ".."'];
        yield 'route_prefix with ".." inside' => [['route_prefix' => '/a/../b'], 'must not be "." or ".."'];
        yield 'relative cache.path' => [['cache' => ['path' => 'var/image-cache']], 'cache.path must be absolute'];
        yield 'empty cache.path' => [['cache' => ['path' => '']], 'cache.path must be absolute'];
        yield 'blur.size above 64' => [['blur' => ['size' => 65]], 'blur.size'];
        yield 'max_width 0' => [['max_width' => 0], 'max_width'];
        yield 'file_permissions as YAML reads 0660' => [['file_permissions' => '0660'], 'YAML reads 0660 as a string'];
        yield 'file_permissions 660 (decimal)' => [['file_permissions' => 660], 'a plain 660 is decimal'];
        yield 'file_permissions without owner read' => [['file_permissions' => 0o200], 'file_permissions'];
        yield 'directory_permissions without owner execute' => [['directory_permissions' => 0o660], 'directory_permissions'];
        yield 'negative file_permissions' => [['file_permissions' => -1], 'file_permissions'];
        yield 'negative directory_permissions' => [['directory_permissions' => -1], 'directory_permissions'];
        yield 'file_permissions true' => [['file_permissions' => true], 'got bool'];
    }

    public function testUsablePermissionsAndPathsAreAccepted(): void
    {
        $container = $this->load([
            'file_permissions' => null,
            'directory_permissions' => 0o2775,
            'cache' => ['path' => '%kernel.project_dir%/var/image-cache'],
            'route_prefix' => '/.well-known/images',
            'blur' => ['size' => 64],
        ]);

        self::assertNull($container->getParameter('id_sign_image.file_permissions'));
        self::assertSame('%kernel.project_dir%/var/image-cache', $container->getParameter('id_sign_image.cache.path'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function envValues(): iterable
    {
        yield 'default_quality' => [['default_quality' => '%env(int:QUALITY)%']];
        yield 'format_quality' => [['format_quality' => ['avif' => '%env(int:QUALITY)%']]];
        yield 'blur' => [['blur' => ['size' => '%env(int:SIZE)%', 'quality' => '%env(int:QUALITY)%']]];
        yield 'max_width' => [['max_width' => '%env(int:SIZE)%']];
        yield 'device_sizes' => [['device_sizes' => ['%env(int:SIZE)%', 640]]];
        yield 'source path' => [['sources' => ['reference' => '%env(DIR)%']]];
        yield 'cache.path' => [['cache' => ['path' => '%env(DIR)%']]];
        yield 'watermark path and size' => [['watermarks' => ['copyright' => ['path' => '%env(DIR)%', 'size' => '%env(int:SIZE)%']]]];
        yield 'default_watermark' => [['watermarks' => ['copyright' => ['path' => '/srv/w.png']], 'default_watermark' => '%env(WATERMARK)%']];
        yield 'permissions' => [['file_permissions' => '%env(int:MODE)%', 'directory_permissions' => '%env(int:MODE)%']];
    }

    /**
     * Symfony validates an env value at build time with a dummy (0, ''); range and path checks in the config tree
     * failed the build for each of these, which v0.4 accepted.
     *
     * @param array<string, mixed> $config
     */
    #[DataProvider('envValues')]
    public function testEnvValuesBuild(array $config): void
    {
        $container = $this->compileWithEnv($config);

        self::assertTrue($container->isCompiled());
    }

    public function testRoutePrefixFromEnvIsRejectedWithAReason(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('cannot come from an environment variable');

        $this->compileWithEnv(['route_prefix' => '%env(PREFIX)%']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compileWithEnv(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->projectDir);
        $container->setParameter('kernel.secret', 'test-secret');
        foreach (['QUALITY' => '75', 'SIZE' => '20', 'DIR' => '/srv/images', 'WATERMARK' => 'copyright', 'MODE' => (string) 0o770, 'PREFIX' => '/img'] as $name => $value) {
            $container->setParameter('env('.$name.')', $value);
        }
        $container->register(RequestStack::class);
        $container->registerExtension(new IdSignImageExtension());
        $container->loadFromExtension('id_sign_image', $config);
        $container->compile(true);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('invalidValues')]
    public function testValueThatBreaksAtRuntimeFailsTheBuild(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->load($config);
    }

    public function testCacheTtlZeroIsAccepted(): void
    {
        // Re-encodes on every request in controller mode — kept for debugging watermark/processing changes.
        self::assertSame(0, $this->load(['cache' => ['ttl' => 0]])->getParameter('id_sign_image.cache.ttl'));
    }

    public function testCachePathFollowsRoutePrefix(): void
    {
        // Public mode serves the URL path from the document root: the cache has to sit where try_files looks.
        self::assertSame('%kernel.project_dir%/public/_image', $this->load([])->getParameter('id_sign_image.cache.path'));
        self::assertSame('%kernel.project_dir%/public/media/images', $this->load(['route_prefix' => '/media/images'])->getParameter('id_sign_image.cache.path'));
    }

    public function testExplicitCachePathWins(): void
    {
        $container = $this->load(['route_prefix' => '/media/images', 'cache' => ['path' => '%kernel.project_dir%/var/image-cache']]);

        self::assertSame('%kernel.project_dir%/var/image-cache', $container->getParameter('id_sign_image.cache.path'));
    }

    public function testNestedRoutePrefixIsAccepted(): void
    {
        self::assertSame('/media/images', $this->load(['route_prefix' => '/media/images'])->getParameter('id_sign_image.route_prefix'));
    }

    public function testLaterConfigFileReplacesDeviceSizes(): void
    {
        // Appending gave [640, 1080, 1080]: the 1080 candidate twice in every srcset.
        $container = $this->load(
            ['device_sizes' => [640, 1080]],
            ['device_sizes' => [1080]],
        );

        self::assertSame([1080], $container->getParameter('id_sign_image.device_sizes'));
    }

    public function testDeviceSizesRejectDuplicates(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must not list a breakpoint twice');

        $this->load(['device_sizes' => [640, 1080, 640]]);
    }

    public function testDeviceSizesRejectZero(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load(['device_sizes' => [0, 640]]);
    }

    public function testFormatsAcceptOutputFormatsInGivenOrder(): void
    {
        self::assertSame(['avif', 'webp'], $this->load([])->getParameter('id_sign_image.formats'));
        self::assertSame(['webp', 'avif', 'jpeg'], $this->load(['formats' => ['webp', 'avif', 'jpeg']])->getParameter('id_sign_image.formats'));
        self::assertSame([], $this->load(['formats' => []])->getParameter('id_sign_image.formats'), 'no <source>, only the fallback <img>');
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidFormats(): iterable
    {
        yield '"jpg" instead of "jpeg"' => [['avif', 'jpg']];
        yield 'uppercase' => [['AVIF']];
        yield 'not an output format' => [['gif']];
    }

    /**
     * @param list<mixed> $formats
     */
    #[DataProvider('invalidFormats')]
    public function testFormatsRejectWhatTheControllerCannotServe(array $formats): void
    {
        // Built fine before, then rendered <source type="application/octet-stream"> with URLs answering 400.
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Permissible values');

        $this->load(['formats' => $formats]);
    }

    public function testFormatsRejectDuplicates(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must not list a format twice');

        $this->load(['formats' => ['avif', 'webp', 'avif']]);
    }

    public function testLaterConfigFileReplacesFormats(): void
    {
        // e.g. base file [avif, webp], a when@prod block drops avif — appending gave [avif, webp, webp]
        $container = $this->load(
            ['formats' => ['avif', 'webp']],
            ['formats' => ['webp']],
        );

        self::assertSame(['webp'], $container->getParameter('id_sign_image.formats'));
    }

    public function testFormatQualityDefaultsToEmpty(): void
    {
        $container = $this->load([]);

        self::assertSame(80, $container->getParameter('id_sign_image.default_quality'));
        self::assertSame([], $container->getParameter('id_sign_image.format_quality'));
    }

    public function testFormatQualityMergesAcrossConfigFiles(): void
    {
        // e.g. base file tunes avif, a when@prod block adds webp and changes avif
        $container = $this->load(
            ['format_quality' => ['avif' => 55]],
            ['format_quality' => ['webp' => 75, 'avif' => 50]],
        );

        self::assertSame(['avif' => 50, 'webp' => 75], $container->getParameter('id_sign_image.format_quality'));
    }

    public function testFormatQualityRejectsJpg(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('format_quality keys must be output formats');

        $this->load(['format_quality' => ['jpg' => 80]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function outOfRangeQualities(): iterable
    {
        yield 'default_quality 0' => [['default_quality' => 0]];
        yield 'default_quality 101' => [['default_quality' => 101]];
        yield 'format_quality 0' => [['format_quality' => ['avif' => 0]]];
        yield 'format_quality 101' => [['format_quality' => ['webp' => 101]]];
        yield 'blur.quality 0' => [['blur' => ['quality' => 0]]];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('outOfRangeQualities')]
    public function testQualityOutsideOneToHundredIsRejected(array $config): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load($config);
    }

    public function testCompiledContainerResolvesFormatQuality(): void
    {
        $container = $this->load(['default_quality' => 70, 'format_quality' => ['avif' => 50]]);
        $container->getDefinition(QualityResolver::class)->setPublic(true);
        $container->compile(true);

        $resolver = $container->get(QualityResolver::class);

        self::assertInstanceOf(QualityResolver::class, $resolver);
        self::assertSame(50, $resolver->resolve('avif'));
        self::assertSame(70, $resolver->resolve('webp'));
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

    public function testLaterConfigFileAddingNamedSourcesKeepsTheConfiguredDefault(): void
    {
        // e.g. base file sets "default", a when@prod block only adds "reference"
        $container = $this->load(
            ['sources' => ['default' => '/srv/images']],
            ['sources' => ['reference' => '/srv/reference']],
        );

        self::assertSame('/srv/images', $container->getDefinition(LocalFilesystemSource::class)->getArgument('$basePath'));
        self::assertTrue($container->hasDefinition('id_sign_image.source.reference'));
    }

    public function testSourceNameWithTrailingNewlineIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Source names must start with a letter');

        $this->load(['sources' => ["reference\n" => '/srv/x']]);
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
