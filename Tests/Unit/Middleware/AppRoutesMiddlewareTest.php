<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Tests\Unit\Middleware;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Middleware\AppRoutesMiddleware;
use Sinso\AppRoutes\Service\FrontendInitialization;
use Sinso\AppRoutes\Service\ResponseCachingService;
use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\Frontend\Page\PageParts;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

#[CoversClass(AppRoutesMiddleware::class)]
final class AppRoutesMiddlewareTest extends UnitTestCase
{
    private Context $context;
    private FrontendInitialization&Stub $frontendInitialization;
    private ResponseCachingService&Stub $responseCachingService;
    private Router&Stub $router;
    private AppRoutesMiddleware $subject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new Context();
        $this->frontendInitialization = self::createStub(FrontendInitialization::class);
        $this->responseCachingService = self::createStub(ResponseCachingService::class);
        $this->router = self::createStub(Router::class);

        $this->subject = new AppRoutesMiddleware(
            $this->context,
            $this->frontendInitialization,
            $this->responseCachingService,
            $this->router,
        );
    }

    #[Test]
    public function noRouteMatchDelegatesToNextHandler(): void
    {
        $urlMatcher = self::createStub(UrlMatcher::class);
        $urlMatcher->method('match')->willThrowException(new ResourceNotFoundException());
        $this->router->method('getUrlMatcher')->willReturn($urlMatcher);

        $expectedResponse = new Response();
        $nextHandler = self::createStub(RequestHandlerInterface::class);
        $nextHandler->method('handle')->willReturn($expectedResponse);

        $request = new ServerRequest('https://example.com/unknown-path');
        $response = $this->subject->process($request, $nextHandler);

        self::assertSame($expectedResponse, $response);
    }

    #[Test]
    public function etagMatchReturns304(): void
    {
        $etag = '"abc123"';
        $request = (new ServerRequest('https://example.com/api/test'))
            ->withHeader('If-None-Match', $etag);

        $originalResponse = (new Response())
            ->withHeader('ETag', $etag)
            ->withStatus(200);

        $reflection = new \ReflectionMethod($this->subject, 'replaceWithNotModifiedResponse');
        $result = $reflection->invoke($this->subject, $request, $originalResponse);

        self::assertSame(304, $result->getStatusCode());
        self::assertSame('', (string)$result->getBody());
    }

    #[Test]
    public function etagMismatchKeepsOriginalResponse(): void
    {
        $request = (new ServerRequest('https://example.com/api/test'))
            ->withHeader('If-None-Match', '"old-etag"');

        $originalResponse = (new Response())
            ->withHeader('ETag', '"new-etag"')
            ->withStatus(200);

        $reflection = new \ReflectionMethod($this->subject, 'replaceWithNotModifiedResponse');
        $result = $reflection->invoke($this->subject, $request, $originalResponse);

        self::assertSame(200, $result->getStatusCode());
    }

    #[Test]
    public function nonOkResponseSkipsEtagCheck(): void
    {
        $request = (new ServerRequest('https://example.com/api/test'))
            ->withHeader('If-None-Match', '"abc123"');

        $originalResponse = (new Response())
            ->withHeader('ETag', '"abc123"')
            ->withStatus(404);

        $reflection = new \ReflectionMethod($this->subject, 'replaceWithNotModifiedResponse');
        $result = $reflection->invoke($this->subject, $request, $originalResponse);

        self::assertSame(404, $result->getStatusCode());
    }

    #[Test]
    public function cacheHitServesFromCache(): void
    {
        $cachedResponse = new Response();
        $request = new ServerRequest('https://example.com/api/test', 'GET');

        $this->responseCachingService->method('has')->willReturn(true);
        $this->responseCachingService->method('isCacheable')->willReturn(true);
        $this->responseCachingService->method('serveFromCache')->willReturn($cachedResponse);

        $response = $this->subject->handleRequestCached(
            ['cache' => true],
            $request,
        );

        self::assertSame($cachedResponse, $response);
    }

    #[Test]
    public function initializeNeededFrontendComponentsSetsPageParts(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord(['tstamp' => 1000, 'SYS_LASTCHANGED' => 2000]);

        $language = self::createStub(SiteLanguage::class);
        $this->frontendInitialization->method('getLanguage')->willReturn($language);
        $this->frontendInitialization->method('createPageInformation')->willReturn($pageInformation);

        $site = self::createStub(SiteInterface::class);
        $site->method('getRootPageId')->willReturn(1);

        $request = (new ServerRequest('https://example.com/api/test'))
            ->withAttribute('site', $site);

        $reflection = new \ReflectionMethod($this->subject, 'initializeNeededFrontendComponents');
        $result = $reflection->invoke($this->subject, [], $request);

        $pageParts = $result->getAttribute('frontend.page.parts');
        self::assertInstanceOf(PageParts::class, $pageParts);
    }

    #[Test]
    public function pagePartsLastChangedUsesSysLastChangedWhenHigher(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord(['tstamp' => 1000, 'SYS_LASTCHANGED' => 2000]);

        $language = self::createStub(SiteLanguage::class);
        $this->frontendInitialization->method('getLanguage')->willReturn($language);
        $this->frontendInitialization->method('createPageInformation')->willReturn($pageInformation);

        $site = self::createStub(SiteInterface::class);
        $site->method('getRootPageId')->willReturn(1);

        $request = (new ServerRequest('https://example.com/api/test'))
            ->withAttribute('site', $site);

        $reflection = new \ReflectionMethod($this->subject, 'initializeNeededFrontendComponents');
        $result = $reflection->invoke($this->subject, [], $request);

        $pageParts = $result->getAttribute('frontend.page.parts');
        self::assertSame(2000, $pageParts->getLastChanged());
    }

    #[Test]
    public function pagePartsLastChangedUsesTstampWhenHigher(): void
    {
        $pageInformation = new PageInformation();
        $pageInformation->setPageRecord(['tstamp' => 3000, 'SYS_LASTCHANGED' => 1000]);

        $language = self::createStub(SiteLanguage::class);
        $this->frontendInitialization->method('getLanguage')->willReturn($language);
        $this->frontendInitialization->method('createPageInformation')->willReturn($pageInformation);

        $site = self::createStub(SiteInterface::class);
        $site->method('getRootPageId')->willReturn(1);

        $request = (new ServerRequest('https://example.com/api/test'))
            ->withAttribute('site', $site);

        $reflection = new \ReflectionMethod($this->subject, 'initializeNeededFrontendComponents');
        $result = $reflection->invoke($this->subject, [], $request);

        $pageParts = $result->getAttribute('frontend.page.parts');
        self::assertSame(3000, $pageParts->getLastChanged());
    }
}
