<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class AppRoutesMiddleware implements MiddlewareInterface
{
    private const CACHEABLE_REQUEST_METHODS = ['GET', 'HEAD'];

    protected FrontendInterface $cache;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cache = $cacheManager->getCache('pages');
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler = null): ResponseInterface
    {
        $router = GeneralUtility::makeInstance(Router::class);
        try {
            $parameters = $router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (MethodNotAllowedException|ResourceNotFoundException $e) {
            // app routes did not match. go on with regular TYPO3 stack.
            return $handler->handle($request);
        }
        $cacheKey = 'appRoutes_' . md5(serialize($parameters));
        if (!empty($parameters['cache']) && $this->cache->has($cacheKey) && in_array($request->getMethod(), self::CACHEABLE_REQUEST_METHODS)) {
            $cacheEntry = $this->cache->get($cacheKey);
            /** @var ResponseInterface $response */
            $response = $cacheEntry['response'];
            $body = new Stream('php://temp', 'rw');
            $body->write($cacheEntry['responseBody']);
            $response = $response->withBody($body);
            if (!empty($GLOBALS['TYPO3_CONF_VARS']['FE']['debug'])) {
                $response = $response->withAddedHeader(
                    'X-APP-ROUTES-CACHED',
                    date(
                        $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] . ' ' . $GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'],
                        $cacheEntry['tstamp']
                    )
                );
            }
            return $response; // served from cache
        }
        $response = $this->handleWithParameters(
            $parameters,
            $request->withQueryParams(array_merge(
                $request->getQueryParams(),
                $parameters
            ))
        );
        if (!empty($parameters['cache'])) {
            $this->storeCacheEntry($request, $response, $cacheKey);
        }
        return $response;
    }

    protected function storeCacheEntry(ServerRequestInterface $request, ResponseInterface $response, string $cacheKey): void
    {
        if (!in_array($request->getMethod(), self::CACHEABLE_REQUEST_METHODS)) {
            return;
        }
        $lifetime = null; // use the default lifetime of the cache
        $cacheControlHeaders = $response->getHeader('Cache-Control');
        foreach ($cacheControlHeaders as $cacheControlHeader) {
            $valueParts = GeneralUtility::trimExplode(',', $cacheControlHeader);
            foreach ($valueParts as $valuePart) {
                if ($valuePart === 'no-cache' || $valuePart === 'no-store') {
                    return;
                }
                [$key, $value] = GeneralUtility::trimExplode('=', $valuePart);
                if ($key === 'max-age') {
                    $lifetime = $value;
                }
            }
        }
        $cacheTags = $this->getTypoScriptFrontendController() instanceof TypoScriptFrontendController ? $this->getTypoScriptFrontendController()->getPageCacheTags() : [];
        $cacheEntry = [
            'response' => $response,
            'responseBody' => (string)$response->getBody(),
            'tstamp' => $GLOBALS['EXEC_TIME'],
        ];
        $this->cache->set($cacheKey, $cacheEntry, $cacheTags, $lifetime);
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
            $this->bootFrontendController($feUserAuthentication, $site, $language, $request);

            if ((new Typo3Version())->getMajorVersion() >= 12) {
                $frontendTypoScript = new FrontendTypoScript(new RootNode(), []);
                $request = $request->withAttribute('frontend.controller', $GLOBALS['TSFE']);
                $request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
            }
        }

        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $handler->handle($request);
    }

    protected function bootFrontendController(FrontendUserAuthentication $frontendUserAuthentication, SiteInterface $site, SiteLanguage $language, ServerRequestInterface $request): void
    {
        if ($this->getTypoScriptFrontendController() instanceof TypoScriptFrontendController) {
            return;
        }

        if (
            VersionNumberUtility::convertVersionNumberToInteger(VersionNumberUtility::getCurrentTypo3Version()) >=
            VersionNumberUtility::convertVersionNumberToInteger('11.0.0')
        ) {
            $controller = GeneralUtility::makeInstance(
                TypoScriptFrontendController::class,
                GeneralUtility::makeInstance(Context::class),
                $site,
                $language,
                new PageArguments($site->getRootPageId(), '0', []),
                $frontendUserAuthentication
            );
            $controller->determineId($request);
            if ((new Typo3Version())->getMajorVersion() < 12) {
                $controller->getConfigArray();
            }
        } else {
            // for TYPO3 v10
            $controller = GeneralUtility::makeInstance(
                TypoScriptFrontendController::class,
                GeneralUtility::makeInstance(Context::class),
                $site,
                $language,
                new PageArguments($site->getRootPageId(), '0', [])
            );
            $controller->fe_user = $frontendUserAuthentication;
            $controller->fetch_the_id();
            $controller->getConfigArray();
            $controller->settingLanguage();
        }

        $controller->newCObj();
        $GLOBALS['TSFE'] = $controller;
        $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
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
