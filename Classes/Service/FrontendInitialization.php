<?php

declare(strict_types=1);

namespace Sinso\AppRoutes\Service;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

class FrontendInitialization
{
    public function __construct(
        private readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
        private readonly PageInformationFactory $pageInformationFactory,
        #[Autowire(service: 'cache.typoscript')]
        private readonly PhpFrontend $typoScriptCache,
    ) {}

    public function createTyposcriptFrontendController(ServerRequestInterface $request): TypoScriptFrontendController
    {
        $pageInformation = $this->pageInformationFactory->create($request);

        $controller = GeneralUtility::makeInstance(TypoScriptFrontendController::class);
        $controller->initializePageRenderer($request);
        $controller->initializeLanguageService($request);
        $controller->id = $pageInformation->getId();
        $controller->page = $pageInformation->getPageRecord();
        $controller->contentPid = $pageInformation->getContentFromPid();
        $controller->rootLine = $pageInformation->getRootLine();
        $controller->config['rootLine'] = $pageInformation->getLocalRootLine();

        $controller->register['SYS_LASTCHANGED'] = (int)$pageInformation->getPageRecord()['tstamp'];
        if ($controller->register['SYS_LASTCHANGED'] < (int)$pageInformation->getPageRecord()['SYS_LASTCHANGED']) {
            $controller->register['SYS_LASTCHANGED'] = (int)$pageInformation->getPageRecord()['SYS_LASTCHANGED'];
        }
        $request = $request->withAttribute('frontend.controller', $controller);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $GLOBALS['TSFE'] = $controller;

        return $controller;
    }

    public function createPageInformation(ServerRequestInterface $request): PageInformation
    {
        return $this->pageInformationFactory->create($request);
    }

    public function createFrontendTypoScript(ServerRequestInterface $request): FrontendTypoScript
    {
        $pageInformation = $this->pageInformationFactory->create($request);
        $site = $request->getAttribute('site');

        $frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
            $site,
            $pageInformation->getSysTemplateRows(),
            [],
            $this->typoScriptCache,
        );
        $pageArguments = new PageArguments($site->getRootPageId(), '0', [], [], $request->getQueryParams());
        return $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $site,
            $pageInformation->getSysTemplateRows(),
            [],
            $pageArguments->getPageType(),
            $this->typoScriptCache,
            $request,
        );
    }

    public function getLanguage(ServerRequestInterface $request): SiteLanguage
    {
        $site = $request->getAttribute('site');
        if (is_null($site) || $site instanceof NullSite) {
            $sites = GeneralUtility::makeInstance(SiteFinder::class)->getAllSites();
            $site = $sites[array_key_first($sites)];
        }
        $languageUid = (int)($request->getQueryParams()['L'] ?? 0);
        foreach ($site->getLanguages() as $siteLanguage) {
            if ($siteLanguage->getLanguageId() === $languageUid) {
                return $siteLanguage;
            }
        }
        return $site->getDefaultLanguage();
    }
}
