<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\Service;

use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RoutesConfigurationLoader
{
    public const APP_ROUTES_YAML_PATH = 'Configuration/AppRoutes.yaml';

    /**
     * @var array
     */
    protected $routesConfiguration = [];

    /**
     * @var YamlFileLoader
     */
    protected $yamlFileLoader;

    public function __construct(CacheManager $cacheManager, YamlFileLoader $yamlFileLoader)
    {
        $cache = $cacheManager->getCache('app_routes');
        $this->yamlFileLoader = $yamlFileLoader;

        $key = 'appRoutesConfiguration';
        if ($cache->has($key)) {
            $this->routesConfiguration = $cache->get($key);
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
        $cache->set($key, $routesConfiguration);
    }

    public function getRoutesConfiguration(): array
    {
        return $this->routesConfiguration;
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
