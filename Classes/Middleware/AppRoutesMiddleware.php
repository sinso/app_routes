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
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class AppRoutesMiddleware implements MiddlewareInterface
{
    private Router $router;
    private ResponseCachingService $responseCachingService;

    public function __construct(Router $router, ResponseCachingService $responseCachingService)
    {
        $this->router = $router;
        $this->responseCachingService = $responseCachingService;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler = null): ResponseInterface
    {
        try {
            $parameters = $this->router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (MethodNotAllowedException|ResourceNotFoundException $e) {
            // app routes did not match. go on with regular TYPO3 stack.
            return $handler->handle($request);
        }
        $cacheKey = 'appRoutes_' . md5(serialize($parameters));
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
        /** @var SiteInterface $site */
        $site = $request->getAttribute('site');
        if (is_null($site) || $site instanceof NullSite) {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
            $site = $sites[array_key_first($sites)];
        }
        $language = $this->getLanguage($site, $request);
        $request = $request->withAttribute('language', $language);
        GeneralUtility::makeInstance(Context::class)->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        if (empty($parameters['handler'])) {
            throw new \Exception('Route must return a handler parameter', 1604066046);
        }
        $handler = GeneralUtility::makeInstance($parameters['handler']);
        if (!$handler instanceof RequestHandlerInterface) {
            throw new \Exception('Route must return a handler parameter which implements ' . RequestHandlerInterface::class, 1604066102);
        }
        if ($parameters['requiresTsfe'] ?? false) {
            /** @var FrontendUserAuthentication $feUserAuthentication */
            $feUserAuthentication = $request->getAttribute('frontend.user');
            $request = $this->bootFrontendController($feUserAuthentication, $site, $language, $request);

            if ((new Typo3Version())->getMajorVersion() >= 12) {
                $tsfe = $request->getAttribute('frontend.controller');
                $frontendTypoScript = new FrontendTypoScript(new RootNode(), []);
                $frontendTypoScript->setSetupTree(new RootNode());
                $frontendTypoScript->setSetupArray($tsfe->tmpl->setup);
                $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
            }
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $handler->handle($request);
    }

    protected function bootFrontendController(FrontendUserAuthentication $frontendUserAuthentication, SiteInterface $site, SiteLanguage $language, ServerRequestInterface $request): ServerRequestInterface
    {
        if ($this->getTypoScriptFrontendController() instanceof TypoScriptFrontendController) {
            return $request;
        }

        $tsfeInitializationService = GeneralUtility::makeInstance(Tsfe::class);
        $controller = $tsfeInitializationService->getTsfeByPageIdAndLanguageId($site->getRootPageId(), $language->getLanguageId());
        $controller->newCObj($request);
        $GLOBALS['TSFE'] = $controller;
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        return $request->withAttribute('frontend.controller', $controller);
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
