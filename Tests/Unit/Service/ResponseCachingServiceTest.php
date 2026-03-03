<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Sinso\AppRoutes\Service\ResponseCachingService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(ResponseCachingService::class)]
final class ResponseCachingServiceTest extends UnitTestCase
{
    private FrontendInterface&Stub $cache;
    private ResponseCachingService $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = self::createStub(FrontendInterface::class);
        $cacheManager = self::createStub(CacheManager::class);
        $cacheManager->method('getCache')->willReturn($this->cache);

        $this->subject = new ResponseCachingService($cacheManager);
    }

    #[Test]
    public function isCacheableReturnsTrueForGetRequest(): void
    {
        $request = new ServerRequest('https://example.com/api/test', 'GET');
        self::assertTrue($this->subject->isCacheable($request));
    }

    #[Test]
    public function isCacheableReturnsTrueForHeadRequest(): void
    {
        $request = new ServerRequest('https://example.com/api/test', 'HEAD');
        self::assertTrue($this->subject->isCacheable($request));
    }

    #[Test]
    public function isCacheableReturnsFalseForPostRequest(): void
    {
        $request = new ServerRequest('https://example.com/api/test', 'POST');
        self::assertFalse($this->subject->isCacheable($request));
    }

    #[Test]
    public function isCacheableReturnsFalseForPutRequest(): void
    {
        $request = new ServerRequest('https://example.com/api/test', 'PUT');
        self::assertFalse($this->subject->isCacheable($request));
    }

    #[Test]
    public function isCacheableReturnsFalseForDeleteRequest(): void
    {
        $request = new ServerRequest('https://example.com/api/test', 'DELETE');
        self::assertFalse($this->subject->isCacheable($request));
    }

    #[Test]
    public function hasDelegatesToCache(): void
    {
        $this->cache->method('has')->willReturn(true);
        self::assertTrue($this->subject->has('testKey'));
    }

    #[Test]
    public function hasReturnsFalseForMissingKey(): void
    {
        $this->cache->method('has')->willReturn(false);
        self::assertFalse($this->subject->has('missingKey'));
    }
}
