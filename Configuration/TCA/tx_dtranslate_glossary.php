<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary',
        'label' => 'source_term',
        'label_alt' => 'target_term',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'sortby' => 'sorting',
        'rootLevel' => -1,
        'hideTable' => true,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/content/content-text.svg',
    ],
    'columns' => [
        'hidden' => [
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle', 'default' => 0],
        ],
        'profile' => [
            'config' => ['type' => 'passthrough'],
        ],
        'target_language' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.target_language',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.target_language.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \Dreistein\DTranslate\Backend\ItemsProc\SiteLanguageItemsProc::class . '->populate',
                'default' => -1,
            ],
        ],
        'source_term' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.source_term',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim', 'required' => true],
        ],
        'target_term' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.target_term',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim', 'required' => true],
        ],
        'note' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.note',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim'],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'source_term, target_term, target_language, note, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.access, hidden'],
    ],
];
