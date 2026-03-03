<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Sinso\AppRoutes\Service\Router;
use Sinso\AppRoutes\Service\RoutesConfigurationLoader;
use Symfony\Component\Routing\RouteCollection;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(Router::class)]
final class RouterTest extends UnitTestCase
{
    private RoutesConfigurationLoader&Stub $routeFilesLoader;
    private FrontendInterface&Stub $runtimeCache;
    private Router $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $cacheManager = self::createStub(CacheManager::class);
        $this->routeFilesLoader = self::createStub(RoutesConfigurationLoader::class);
        $this->runtimeCache = self::createStub(FrontendInterface::class);
        $cacheManager->method('getCache')->willReturn($this->runtimeCache);

        $this->subject = new Router($cacheManager, $this->routeFilesLoader);
    }

    #[Test]
    public function getRoutesReturnsPopulatedRouteCollection(): void
    {
        $this->runtimeCache->method('has')->willReturn(false);

        $this->routeFilesLoader->method('getRoutesConfiguration')->willReturn([
            'testApp' => [
                'prefix' => '/api/v1',
                'routes' => [
                    [
                        'name' => 'list',
                        'path' => '/items',
                        'defaults' => ['handler' => 'TestHandler'],
                    ],
                    [
                        'name' => 'detail',
                        'path' => '/items/{id}',
                        'defaults' => ['handler' => 'TestHandler'],
                    ],
                ],
            ],
        ]);

        $routes = $this->subject->getRoutes();

        self::assertInstanceOf(RouteCollection::class, $routes);
        self::assertCount(2, $routes);
        self::assertNotNull($routes->get('testApp.list'));
        self::assertNotNull($routes->get('testApp.detail'));
        self::assertSame('/api/v1/items', $routes->get('testApp.list')->getPath());
        self::assertSame('/api/v1/items/{id}', $routes->get('testApp.detail')->getPath());
    }

    #[Test]
    public function getRoutesReturnsCachedRouteCollection(): void
    {
        $cachedRoutes = new RouteCollection();
        $this->runtimeCache->method('has')->willReturn(true);
        $this->runtimeCache->method('get')->willReturn($cachedRoutes);

        $routes = $this->subject->getRoutes();

        self::assertSame($cachedRoutes, $routes);
    }

    #[Test]
    public function getRoutesHandlesEmptyConfiguration(): void
    {
        $this->runtimeCache->method('has')->willReturn(false);
        $this->routeFilesLoader->method('getRoutesConfiguration')->willReturn([]);

        $routes = $this->subject->getRoutes();

        self::assertCount(0, $routes);
    }
}
