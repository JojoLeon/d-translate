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
        'searchFields' => 'source_term,target_term,note',
    ],
    'columns' => [
        'hidden' => [
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle', 'default' => 0],
        ],
        'profile' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.profile',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.profile.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                // 0 = shared by every profile; the foreign_table rows follow.
                'items' => [
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.profile.all', 'value' => 0],
                ],
                'foreign_table' => 'tx_dtranslate_profile',
                'foreign_table_where' => 'AND {#tx_dtranslate_profile}.{#deleted} = 0 ORDER BY tx_dtranslate_profile.title',
                'maxitems' => 1,
                'default' => 0,
            ],
        ],
        'target_language' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.target_language',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_glossary.target_language.description',
            // A key, not a number: '-1' (every language), 'en' (a language, every
            // variant), 'en-GB' (one regional variant) or '15' (one site language
            // id, as entries were scoped before 0.2 — still honoured as is).
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \Dreistein\DTranslate\Backend\ItemsProc\SiteLanguageItemsProc::class . '->populate',
                'default' => '-1',
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
        '0' => ['showitem' => 'profile, source_term, target_term, target_language, note, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.access, hidden'],
    ],
];
