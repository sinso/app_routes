<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\ConfigurationModuleProvider;

use Sinso\AppRoutes\Service\RoutesConfigurationLoader;
use TYPO3\CMS\Lowlevel\ConfigurationModuleProvider\AbstractProvider;

class AppRoutesProvider extends AbstractProvider
{
    /**
     * @var RoutesConfigurationLoader
     */
    protected $routesConfigurationLoader;

    public function __construct(RoutesConfigurationLoader $routesConfigurationLoader)
    {
        $this->routesConfigurationLoader = $routesConfigurationLoader;
    }

    public function getConfiguration(): array
    {
        return $this->routesConfigurationLoader->getRoutesConfiguration();
    }
}
