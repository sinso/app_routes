<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\ResponseCachingService;
use Sinso\AppRoutes\Service\Router;
use Sinso\AppRoutes\Service\Tsfe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Cache\CacheInstruction;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

class AppRoutesMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
        private readonly PageInformationFactory $pageInformationFactory,
        #[Autowire(service: 'cache.typoscript')]
        private readonly PhpFrontend $typoScriptCache,
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
        $response = $this->replaceWithNotModifiedResponse($request, $response);

        return $response;
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
        if (empty($parameters['handler'])) {
            throw new \Exception('Route must return a handler parameter', 1604066046);
        }
        $handler = GeneralUtility::makeInstance($parameters['handler']);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \Exception('Route must return a handler parameter which implements ' . RequestHandlerInterface::class, 1604066102);
        }
        $site = $request->getAttribute('site');
        $language = $this->getLanguage($site, $request);
        $request = $request->withAttribute('language', $language);

        if ($parameters['requiresTsfe'] ?? false) {
            $feUserAuthentication = $request->getAttribute('frontend.user');
            $request = $this->bootFrontendController($feUserAuthentication, $site, $language, $request);
            $controller = $request->getAttribute('frontend.controller');
            $context = GeneralUtility::makeInstance(Context::class);
            $cacheInstruction = $request->getAttribute('frontend.cache.instruction', new CacheInstruction());
            $request = $request->withAttribute('frontend.cache.instruction', $cacheInstruction);
            $pageArguments = new PageArguments($site->getRootPageId(), '0', []);
            $request = $request->withAttribute('routing', $pageArguments);
            $context->setAspect('frontend.preview', new PreviewAspect(false));
            $pageInformation = $this->pageInformationFactory->create($request);
            $request = $request->withAttribute('frontend.page.information', $pageInformation);
            $expressionMatcherVariables = $this->getExpressionMatcherVariables($site, $request, $controller);
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
                $site,
                $pageInformation->getSysTemplateRows(),
                // $originalRequest does not contain site ...
                $expressionMatcherVariables,
                $this->typoScriptCache,
            );
            // Note, that we need the full TypoScript setup array, which is required for links created by
            // DatabaseRecordLinkBuilder. This should be kept in mind when TSFE will be removed in v14.
            $frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
                true,
                $frontendTypoScript,
                $site,
                $pageInformation->getSysTemplateRows(),
                $expressionMatcherVariables,
                '0',
                $this->typoScriptCache,
                null
            );
            $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
        }
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $handler->handle($request);
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

    protected function bootFrontendController(FrontendUserAuthentication $frontendUserAuthentication, SiteInterface $site, SiteLanguage $language, ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->getTypoScriptFrontendController() instanceof TypoScriptFrontendController) {
            return $request;
        }

        $tsfeInitializationService = GeneralUtility::makeInstance(Tsfe::class);
        $pageInformation = new PageInformation();
        $controller = $tsfeInitializationService->getTsfeByPageIdAndLanguageId($site->getRootPageId(), $language->getLanguageId());
        $pageInformation->setId($controller->id);
        $pageInformation->setPageRecord(BackendUtility::getRecord('pages', $controller->id));
        $pageInformation->setContentFromPid($controller->id);
        $request = $request->withAttribute('frontend.page.information', $pageInformation);
        $request = $request->withAttribute('frontend.user', $frontendUserAuthentication);

        $controller->newCObj($request);
        $GLOBALS['TSFE'] = $controller;
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        return $request->withAttribute('frontend.controller', $controller);
    }

    protected function getExpressionMatcherVariables(SiteInterface $site, ServerRequestInterface $request, TypoScriptFrontendController $controller): array
    {
        $pageInformation = $request->getAttribute('frontend.page.information');
        $topDownRootLine = $pageInformation->getRootLine();
        $localRootline = $pageInformation->getLocalRootLine();
        ksort($topDownRootLine);
        return [
            'request' => $request,
            'pageId' => $pageInformation->getId(),
            'page' => $pageInformation->getPageRecord(),
            'fullRootLine' => $topDownRootLine,
            'localRootLine' => $localRootline,
            'site' => $site,
            'siteLanguage' => $request->getAttribute('language'),
            'tsfe' => $controller,
        ];
    }

    protected function getLanguage(SiteInterface $site, ServerRequestInterface $request): SiteLanguage
    {
        $languageUid = (int)($request->getQueryParams()['L'] ?? 0);
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $languageUid) {
                return $siteLanguage;
            }
        }
        return $site->getDefaultLanguage();
    }

    protected function getTypoScriptFrontendController(): ?TypoScriptFrontendController
    {
        return $GLOBALS['TSFE'] ?? null;
    }
}
