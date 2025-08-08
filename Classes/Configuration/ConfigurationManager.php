<?php

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Sinso\AppRoutes\Configuration;

use Doctrine\DBAL\Exception as DBALException;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateRepository;
use TYPO3\CMS\Core\TypoScript\IncludeTree\SysTemplateTreeBuilder;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\ConditionVerdictAwareIncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\IncludeTree\Traverser\IncludeTreeTraverser;
use TYPO3\CMS\Core\TypoScript\Tokenizer\LossyTokenizer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Configuration manager old the configuration instance.
 * Singleton
 */
class ConfigurationManager implements SingletonInterface
{
    public function getCoreTypoScriptFrontendByRequest(ServerRequestInterface $request): FrontendTypoScript
    {
        $typo3Site = $request->getAttribute('site');
        $sysTemplateRows = $this->getSysTemplateRowsForAssociatedContextPageId($request);

        $frontendTypoScriptFactory = GeneralUtility::makeInstance(
            FrontendTypoScriptFactory::class,
            GeneralUtility::makeInstance(ContainerInterface::class),
            GeneralUtility::makeInstance(EventDispatcherInterface::class),
            GeneralUtility::makeInstance(SysTemplateTreeBuilder::class),
            GeneralUtility::makeInstance(LossyTokenizer::class),
            GeneralUtility::makeInstance(IncludeTreeTraverser::class),
            GeneralUtility::makeInstance(ConditionVerdictAwareIncludeTreeTraverser::class),
        );

        $expressionMatcherVariables = ['request' => $request];
        $pageInformation = $request->getAttribute('frontend.page.information');
        if ($pageInformation instanceof PageInformation) {
            $expressionMatcherVariables['pageId'] = $pageInformation->getId();
            $expressionMatcherVariables['page'] = $pageInformation->getPageRecord();
        } else {
            $pageUid = (int)(
                $request->getParsedBody()['id']
                ?? $request->getQueryParams()['id']
                ?? $request->getAttribute('site')?->getRootPageId()
            );
            if ($pageUid !== 0) {
                $expressionMatcherVariables['pageId'] = $pageUid;
                $expressionMatcherVariables['page'] = BackendUtility::getRecord('pages', $pageUid);
            }
        }
        $site = $request->getAttribute('site');
        if ($site instanceof Site) {
            $expressionMatcherVariables['site'] = $site;
        }
        $frontendTypoScript = $frontendTypoScriptFactory->createSettingsAndSetupConditions(
            $typo3Site,
            $sysTemplateRows,
            $expressionMatcherVariables,
            null,
        );
        return $frontendTypoScriptFactory->createSetupConfigOrFullSetup(
            true,
            $frontendTypoScript,
            $typo3Site,
            $sysTemplateRows,
            $expressionMatcherVariables,
            '0',
            null,
            null,
        );
    }

    /**
     * @return array|array{
     *    'uid': int,
     *    'pid': int,
     *    'tstamp': int,
     *    'crdate': int,
     *    'deleted': int,
     *    'hidden': int,
     *    'starttime': int,
     *    'endtime': int,
     *    'sorting': int,
     *    'description': string,
     *    'tx_impexp_origuid': int,
     *    'title': string,
     *    'root': int,
     *    'clear': int,
     *    'constants': string,
     *    'include_static_file': string,
     *    'basedOn': string,
     *    'includeStaticAfterBasedOn': int,
     *    'config': string,
     *    'static_file_mode': int,
     * }
     *
     * @throws DBALException
     */
    protected function getSysTemplateRowsForAssociatedContextPageId(ServerRequestInterface $request): array
    {
        $pageUid = (int)(
            $request->getParsedBody()['id']
            ?? $request->getQueryParams()['id']
            ?? $request->getAttribute('frontend.controller')?->id
            ?? $request->getAttribute('site')?->getRootPageId()
        );

        /** @var Context $coreContext */
        $coreContext = clone GeneralUtility::makeInstance(Context::class);
        $coreContext->setAspect(
            'visibility',
            GeneralUtility::makeInstance(
                VisibilityAspect::class,
                false,
                false,
            ),
        );
        /** @var RootLineUtility $rootlineUtility */
        $rootlineUtility = GeneralUtility::makeInstance(
            RootLineUtility::class,
            $pageUid,
            '', // @todo: tag: MountPoint,
            $coreContext,
        );
        $rootline = $rootlineUtility->get();
        if ($rootline === []) {
            return [];
        }

        /** @var SysTemplateRepository $sysTemplateRepository */
        $sysTemplateRepository = GeneralUtility::makeInstance(
            SysTemplateRepository::class,
            GeneralUtility::makeInstance(EventDispatcherInterface::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
            $coreContext,
        );

        return $sysTemplateRepository->getSysTemplateRowsByRootline(
            $rootline,
            $request,
        );
    }
}
