<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Sinso\AppRoutes\Service\RoutesConfigurationLoader;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Configuration\Loader\YamlFileLoader;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(RoutesConfigurationLoader::class)]
final class RoutesConfigurationLoaderTest extends UnitTestCase
{
    private FrontendInterface&Stub $cache;
    private PackageManager&Stub $packageManager;
    private YamlFileLoader&Stub $yamlFileLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = self::createStub(FrontendInterface::class);
        $this->packageManager = self::createStub(PackageManager::class);
        $this->yamlFileLoader = self::createStub(YamlFileLoader::class);
    }

    private function createSubject(): RoutesConfigurationLoader
    {
        $cacheManager = self::createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($this->cache);

        return new RoutesConfigurationLoader($cacheManager, $this->packageManager, $this->yamlFileLoader);
    }

    #[Test]
    public function returnsCachedConfiguration(): void
    {
        $expectedConfig = ['testApp' => ['routes' => []]];
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn($expectedConfig);

        $subject = $this->createSubject();
        $result = $subject->getRoutesConfiguration();

        self::assertSame($expectedConfig, $result);
    }

    #[Test]
    public function returnsSameResultOnSecondCall(): void
    {
        $expectedConfig = ['testApp' => ['routes' => []]];
        $this->cache->method('has')->willReturn(true);
        $this->cache->method('get')->willReturn($expectedConfig);

        $subject = $this->createSubject();
        $first = $subject->getRoutesConfiguration();
        $second = $subject->getRoutesConfiguration();

        self::assertSame($first, $second);
    }
}
