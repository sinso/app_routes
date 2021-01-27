<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RoutesConfigurationLoader
{
    public const APP_ROUTES_YAML_PATH = 'Configuration/AppRoutes.yaml';

    /**
     * @var FrontendInterface
     */
    protected $cache;

    /**
     * @var array
     */
    protected $routesConfiguration;

    /**
     * @var YamlFileLoader
     */
    protected $yamlFileLoader;

    public function __construct(CacheManager $cacheManager, YamlFileLoader $yamlFileLoader)
    {
        $this->cache = $cacheManager->getCache('app_routes');
        $this->yamlFileLoader = $yamlFileLoader;
    }

    public function getRoutesConfiguration(): array
    {
        if (!is_array($this->routesConfiguration)) {
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
        $packageManager = GeneralUtility::makeInstance(PackageManager::class);

        $paths = [];
        foreach ($packageManager->getActivePackages() as $package) {
            $possiblePath = $package->getPackagePath() . self::APP_ROUTES_YAML_PATH;
            if (is_readable($possiblePath)) {
                $paths[] = $possiblePath;
            }
        }

        return $paths;
    }
}
