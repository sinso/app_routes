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
        } catch (MethodNotAllowedException|ResourceNotFoundException $e) {
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
        /** @var SiteInterface $site */
        $site = $request->getAttribute('site');
        $language = $this->getLanguage($site, $request);
        $request = $request->withAttribute('language', $language);
        GeneralUtility::makeInstance(Context::class)->setAspect('language', LanguageAspectFactory::createFromSiteLanguage($language));

        $GLOBALS['TYPO3_REQUEST'] = $request;

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
            $this->bootFrontendController($feUserAuthentication, $site, $language);
        }

        return $handler->handle($request);
    }

    protected function bootFrontendController(FrontendUserAuthentication $frontendUserAuthentication, SiteInterface $site, SiteLanguage $language): void
    {
        if ($GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
            return;
        }
        // @extensionScannerIgnoreLine - the extension scanner shows a strong warning, because it detects that the fourth constructor argument of TSFE is used which was deprecated in TYPO3 v9, however v10 introduced new constructor arguments which we're using here
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
}
