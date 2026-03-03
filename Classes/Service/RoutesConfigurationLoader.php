<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;

class RoutesConfigurationLoader
{
    public const APP_ROUTES_YAML_PATH = 'Configuration/AppRoutes.yaml';

    private readonly FrontendInterface $cache;
    private array $routesConfiguration;

    public function __construct(
        CacheManager $cacheManager,
        private readonly PackageManager $packageManager,
        private readonly YamlFileLoader $yamlFileLoader,
    ) {
        $this->cache = $cacheManager->getCache('app_routes');
    }

    public function getRoutesConfiguration(): array
    {
        if (!isset($this->routesConfiguration)) {
            $this->loadRoutesConfiguration();
        }
        return $this->routesConfiguration;
    }

    protected function loadRoutesConfiguration(): void
    {
        $key = 'appRoutesConfiguration';
        if ($this->cache->has($key)) {
            $this->routesConfiguration = $this->cache->get($key);
            return;
        }

        $routesConfiguration = [];
        foreach ($this->findAppRouteYamlFiles() as $yamlFile) {
            $routesConfiguration = array_merge_recursive(
                $routesConfiguration,
                $this->yamlFileLoader->load($yamlFile)
            );
        }
        $this->routesConfiguration = $routesConfiguration;
        $this->cache->set($key, $routesConfiguration);
    }

    protected function findAppRouteYamlFiles(): array
    {
        $paths = [];
        foreach ($this->packageManager->getActivePackages() as $package) {
            $possiblePath = $package->getPackagePath() . self::APP_ROUTES_YAML_PATH;
            if (is_readable($possiblePath)) {
                $paths[] = $possiblePath;
            }
        }

        return $paths;
    }
}
