<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\FrontendInitialization;
use Sinso\AppRoutes\Service\ResponseCachingService;
use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\Frontend\Page\PageParts;

class AppRoutesMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly FrontendInitialization $frontendInitialization,
        private readonly ResponseCachingService $responseCachingService,
        private readonly Router $router,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler = null): ResponseInterface
    {
        try {
            $parameters = $this->router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (MethodNotAllowedException|ResourceNotFoundException) {
            // app routes did not match. go on with regular TYPO3 stack.
            return $handler->handle($request);
        }
        $response = $this->handleRequestCached($parameters, $request);
        return $this->replaceWithNotModifiedResponse($request, $response);
    }

    public function handleRequestCached(array $parameters, ServerRequestInterface $request): ResponseInterface
    {
        $ingredients = [
            'routeParameters' => $parameters,
            'language' => (int)($request->getAttribute('language')?->getLanguageId() ?? $request->getQueryParams()['L'] ?? 0),
            'site' => $request->getAttribute('site')?->getIdentifier(),
        ];
        $cacheKey = 'appRoutes_' . md5(serialize($ingredients));
        if (!empty($parameters['cache']) && $this->responseCachingService->has($cacheKey) && $this->responseCachingService->isCacheable($request)) {
            return $this->responseCachingService->serveFromCache($cacheKey);
        }

        // todo: instead of mixing all routing $parameters into query parameters, we should add an attribute to bundle that data
        $response = $this->handleWithParameters(
            $parameters,
            $request->withQueryParams(array_merge(
                $request->getQueryParams(),
                $parameters
            ))
        );
        if (!empty($parameters['cache']) && $response->getStatusCode() < 400) {
            $response = $this->responseCachingService->storeCacheEntry($request, $response, $cacheKey);
        }
        return $response;
    }

    protected function handleWithParameters(array $parameters, ServerRequestInterface $request): ResponseInterface
    {
        $request = $this->initializeNeededFrontendComponents($parameters, $request);
        $GLOBALS['TYPO3_REQUEST'] = $request;

        if (empty($parameters['handler'])) {
            throw new \Exception('Route must return a handler parameter', 1604066046);
        }
        $handler = GeneralUtility::makeInstance($parameters['handler']);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \Exception('Route must return a handler parameter which implements ' . RequestHandlerInterface::class, 1604066102);
        }

        return $handler->handle($request);
    }

    protected function initializeNeededFrontendComponents(array $parameters, ServerRequestInterface $request): ServerRequestInterface
    {
        $site = $request->getAttribute('site');

        // set PageArguments as routing attribute
        $keysToRemove = ['handler', 'requiresTypoScript', 'cache', 'L', '_route'];
        $remainingArguments = array_diff_key($request->getQueryParams(), array_flip($keysToRemove));
        $request = $request->withAttribute('routing', new PageArguments($site->getRootPageId(), '0', [], [], $remainingArguments));

        // language
        $language = $this->frontendInitialization->getLanguage($request);
        $request = $request->withAttribute('language', $language);
        $this->context->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        // context
        if (!$this->context->hasAspect('frontend.preview')) {
            $this->context->setAspect('frontend.preview', new PreviewAspect());
        }

        // page information
        $pageInformation = $this->frontendInitialization->createPageInformation($request);
        $request = $request->withAttribute('frontend.page.information', $pageInformation);

        $pageParts = new PageParts();
        $lastChanged = (int)$pageInformation->getPageRecord()['tstamp'];
        if ($lastChanged < (int)$pageInformation->getPageRecord()['SYS_LASTCHANGED']) {
            $lastChanged = (int)$pageInformation->getPageRecord()['SYS_LASTCHANGED'];
        }
        $pageParts->setLastChanged($lastChanged);
        $request = $request->withAttribute('frontend.page.parts', $pageParts);

        // TypoScript
        if ($parameters['requiresTypoScript'] ?? false) {
            $frontendTypoScript = $this->frontendInitialization->createFrontendTypoScript($request);
            $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
        }

        return $request;
    }

    protected function replaceWithNotModifiedResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($response->getStatusCode() !== 200) {
            return $response;
        }
        if ($request->hasHeader('If-None-Match') && $response->hasHeader('ETag') && $request->getHeader('If-None-Match')[0] === $response->getHeader('ETag')[0]) {
            return $response->withBody(new Stream(fopen('php://temp', 'r+')))->withStatus(304);
        }
        return $response;
    }
}
