<?php

declare(strict_types=1);

use Dreistein\DTranslate\Controller\Backend\GlossaryController;
use Dreistein\DTranslate\Controller\Backend\TranslationModuleController;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;

// The page tree web component moved in v13 (page-tree/ → tree/).
$pageTreeComponent = GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 13
    ? '@typo3/backend/tree/page-tree-element'
    : '@typo3/backend/page-tree/page-tree-element';

return [
    'dtranslate' => [
        'parent' => 'web',
        'position' => ['after' => 'web_list'],
        'access' => 'admin',
        'iconIdentifier' => 'tx-dtranslate-module',
        'navigationComponent' => $pageTreeComponent,
        'labels' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'DTranslate',
        'path' => '/module/web/dtranslate',
        'routes' => [
            '_default' => [
                'target' => TranslationModuleController::class . '::overview',
            ],
            'translate' => [
                'target' => TranslationModuleController::class . '::translate',
            ],
            'start' => [
                'target' => TranslationModuleController::class . '::start',
            ],
            'step' => [
                'target' => TranslationModuleController::class . '::step',
            ],
            'status' => [
                'target' => TranslationModuleController::class . '::status',
            ],
            'cancel' => [
                'target' => TranslationModuleController::class . '::cancel',
            ],
            'glossary' => [
                'target' => GlossaryController::class . '::list',
            ],
        ],
    ],
];
