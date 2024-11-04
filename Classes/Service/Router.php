<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\SingletonInterface;

class Router implements SingletonInterface
{
    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly RoutesConfigurationLoader $routeFilesLoader,
    ) {}

    public function getRoutes(): RouteCollection
    {
        $cacheKey = 'appRoutes_Router_routes';
        if ($this->getCache()->has($cacheKey)) {
            return $this->getCache()->get($cacheKey);
        }
        $routes = new RouteCollection();
        foreach ($this->routeFilesLoader->getRoutesConfiguration() as $appName => $appRoutesConfiguration) {
            $prefix = $appRoutesConfiguration['prefix'] ?? '';
            $routes = $this->populateRouteCollection($routes, $appRoutesConfiguration['routes'], $appName, $prefix);
        }
        $this->getCache()->set($cacheKey, $routes);
        return $routes;
    }

    public function getUrlGenerator(): UrlGenerator
    {
        $context = $this->createRequestContext();
        return new UrlGenerator($this->getRoutes(), $context);
    }

    public function getUrlMatcher(): UrlMatcher
    {
        $context = $this->createRequestContext();
        return new UrlMatcher($this->getRoutes(), $context);
    }

    protected function createRequestContext(): RequestContext
    {
        if (Environment::isCli()) {
            return new RequestContext();
        }

        $request = ServerRequestFactory::fromGlobals();
        $host = (string)idn_to_ascii($request->getUri()->getHost());
        return new RequestContext(
            '',
            $request->getMethod(),
            $host,
            $request->getUri()->getScheme(),
            80,
            443,
            $request->getUri()->getPath()
        );
    }

    protected function populateRouteCollection(RouteCollection $routes, array $routesConfiguration, string $namePrefix, string $pathPrefix): RouteCollection
    {
        foreach ($routesConfiguration as $routeConfiguration) {
            $route = new Route(
                $pathPrefix . $routeConfiguration['path'],
                $routeConfiguration['defaults'] ?? [],
                $routeConfiguration['requirements'] ?? [],
                $routeConfiguration['options'] ?? [],
                $routeConfiguration['host'] ?? '',
                $routeConfiguration['schemes'] ?? [],
                $routeConfiguration['methods'] ?? [],
                $routeConfiguration['condition'] ?? ''
            );
            $routes->add($namePrefix . '.' . $routeConfiguration['name'], $route);
        }
        return $routes;
    }

    private function getCache(): FrontendInterface
    {
        return $this->cacheManager->getCache('runtime');
    }
}
