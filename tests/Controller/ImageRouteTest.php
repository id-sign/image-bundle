<?php

declare(strict_types=1);

namespace IdSign\ImageBundle\Tests\Controller;

use IdSign\ImageBundle\Cache\CachePathResolver;
use IdSign\ImageBundle\Controller\ImageController;
use IdSign\ImageBundle\Service\UrlSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Routing\Loader\YamlFileLoader;

/**
 * config/routes.yaml read the way an application reads it: FrameworkBundle's router resolves %parameters% in the
 * route path, so the route follows route_prefix without a "prefix:" on the import.
 */
class ImageRouteTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function routePrefixes(): iterable
    {
        yield 'default' => ['/_image'];
        yield 'nested' => ['/media/images'];
    }

    #[DataProvider('routePrefixes')]
    public function testRouteMatchesTheUrlsTheBundleGenerates(string $routePrefix): void
    {
        $path = (new CachePathResolver(new UrlSigner('test-secret')))->resolve('@reference/uploads/My Photo.jpg', 800, null, null, 80, 'webp');
        $url = $routePrefix.'/'.CachePathResolver::encodeForUrl($path);

        $match = $this->createRouter($routePrefix)->match($url);

        self::assertSame('id_sign_image', $match['_route']);
        self::assertSame(ImageController::class, $match['_controller']);
        self::assertSame($path, $match['path']);
    }

    public function testRoutePathIsTheRoutePrefix(): void
    {
        $route = $this->createRouter('/media/images')->getRouteCollection()->get('id_sign_image');

        self::assertNotNull($route);
        self::assertSame('/media/images/{path}', $route->getPath());
    }

    private function createRouter(string $routePrefix): Router
    {
        $container = new Container(new ParameterBag(['id_sign_image.route_prefix' => $routePrefix]));
        $container->set('routing.loader', new YamlFileLoader(new FileLocator(__DIR__.'/../../config')));

        return new Router($container, 'routes.yaml');
    }
}
