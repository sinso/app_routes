<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\Service;

use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

class Router implements SingletonInterface
{
    /**
     * @var RouteCollection
     */
    protected $routes;

    /**
     * @var UrlGenerator
     */
    protected $urlGenerator;

    /**
     * @var UrlMatcher
     */
    protected $urlMatcher;

    public function __construct(RoutesConfigurationLoader $routeFilesLoader)
    {
        $routes = new RouteCollection();
        foreach ($routeFilesLoader->getRoutesConfiguration() as $appName => $appRoutesConfiguration) {
            $prefix = $appRoutesConfiguration['prefix'] ?? '';
            $routes = $this->populateRouteCollection($routes, $appRoutesConfiguration['routes'], $appName, $prefix);
        }

        $context = $this->createRequestContext();
        $this->urlMatcher = new UrlMatcher($routes, $context);
        $this->urlGenerator = new UrlGenerator($routes, $context);
        $this->routes = $routes;
    }

    protected function createRequestContext(): RequestContext
    {
        if (TYPO3_REQUESTTYPE === TYPO3_REQUESTTYPE_CLI) {
            return new RequestContext();
        }

        $request = ServerRequestFactory::fromGlobals();
        if (
            VersionNumberUtility::convertVersionNumberToInteger(VersionNumberUtility::getCurrentTypo3Version()) >=
            VersionNumberUtility::convertVersionNumberToInteger('11.2.0')
        ) {
            $host = (string)idn_to_ascii($request->getUri()->getHost());
        } else {
            $host = (string)HttpUtility::idn_to_ascii($request->getUri()->getHost());
        }
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

    public function getRoutes(): RouteCollection
    {
        return $this->routes;
    }

    public function getUrlGenerator(): UrlGenerator
    {
        return $this->urlGenerator;
    }

    public function getUrlMatcher(): UrlMatcher
    {
        return $this->urlMatcher;
    }
}
