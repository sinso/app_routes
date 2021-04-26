<?php

declare(strict_types=1);
namespace Sinso\AppRoutes\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sinso\AppRoutes\Service\Router;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

class AppRoutesMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler = null): ResponseInterface
    {
        $router = GeneralUtility::makeInstance(Router::class);
        try {
            $parameters = $router->getUrlMatcher()->match($request->getUri()->getPath());
        } catch (ResourceNotFoundException $e) {
            // app routes did not match. go on with regular TYPO3 stack.
            return $handler->handle($request);
        }
        $request = $request->withQueryParams(array_merge(
            $request->getQueryParams(),
            $parameters
        ));
        return $this->handleWithParameters($parameters, $request);
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

        if ($parameters['requiresTsfe']) {
            /** @var SiteInterface $site */
            $site = $request->getAttribute('site');
            /** @var FrontendUserAuthentication $feUserAuthentication */
            $feUserAuthentication = $request->getAttribute('frontend.user');
            $language = $this->getLanguage($site, $request);
            GeneralUtility::makeInstance(Context::class)->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));
            $this->bootFrontendController($feUserAuthentication, $site, $language);
        }

        return $handler->handle($request);
    }

    protected function bootFrontendController(FrontendUserAuthentication $frontendUserAuthentication, SiteInterface $site, SiteLanguage $language): TypoScriptFrontendController
    {
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
        $controller->newCObj();
        if (!$GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
            $GLOBALS['TSFE'] = $controller;
        }
        if (!$GLOBALS['TSFE']->sys_page instanceof PageRepository) {
            $GLOBALS['TSFE']->sys_page = GeneralUtility::makeInstance(PageRepository::class);
        }
        return $controller;
    }

    protected function getLanguage(SiteInterface $site, ServerRequestInterface $request): SiteLanguage
    {
        $languageUid = (int)$request->getQueryParams()['L'];
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $languageUid) {
                return $siteLanguage;
            }
        }
        return $site->getDefaultLanguage();
    }
}
