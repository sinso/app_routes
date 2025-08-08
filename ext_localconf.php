<?php

use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;

if (!defined('TYPO3') && !defined('TYPO3')) {
    die();
}
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['app_routes'] = [
    'frontend' => VariableFrontend::class,
    'backend' => FileBackend::class,
    'options' => [
        'defaultLifetime' => 60 * 60 * 24 * 7, // route configuration is cached for a week. clear the cache if you change any AppRoute.yaml file
    ],
];
