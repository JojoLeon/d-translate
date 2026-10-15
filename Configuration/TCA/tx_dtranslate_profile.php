<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile',
        'label' => 'title',
        'label_alt' => 'site_identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'title',
        'rootLevel' => -1,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/actions/actions-translate.svg',
        'searchFields' => 'title,site_identifier',
    ],
    'columns' => [
        'hidden' => [
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle', 'default' => 0],
        ],
        'title' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.title',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim', 'required' => true],
        ],
        'site_identifier' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.site_identifier',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.site_identifier.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'itemsProcFunc' => \Dreistein\DTranslate\Backend\ItemsProc\SiteItemsProc::class . '->populate',
            ],
        ],
        'llm_config' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.llm_config',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_dtranslate_llm_config',
                'foreign_table_where' => 'AND tx_dtranslate_llm_config.hidden = 0 ORDER BY tx_dtranslate_llm_config.title',
                'minitems' => 1,
                'maxitems' => 1,
            ],
        ],
        'source_language' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.source_language',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.source_language.description',
            'config' => ['type' => 'input', 'size' => 20, 'eval' => 'trim', 'placeholder' => 'German'],
        ],
        'formality' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.formality',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.formality.default', 'value' => 'default'],
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.formality.formal', 'value' => 'formal'],
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.formality.informal', 'value' => 'informal'],
                ],
                'default' => 'default',
            ],
        ],
        'style_hint' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.style_hint',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.style_hint.description',
            'config' => ['type' => 'text', 'rows' => 4, 'cols' => 40],
        ],
        'translate_tables' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.translate_tables',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.translate_tables.description',
            'config' => ['type' => 'text', 'rows' => 2, 'cols' => 40, 'placeholder' => 'tx_extension_domain_model_items, tx_yours_domain_model_this'],
        ],
        'additional_fields' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.additional_fields',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.additional_fields.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40, 'placeholder' => "pages.tx_yours_item_flex\ntt_content.tx_myext_custom"],
        ],
        'glossary' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.glossary',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_profile.glossary.description',
            // Not an inline field any more: FormEngine rendered every entry
            // into the form, and a grown glossary (thousands of terms) made the
            // profile take ages to open. Entries live in the module's glossary
            // list (Web → Translation → Glossary); this element links there.
            'config' => [
                'type' => 'user',
                'renderType' => 'dtranslateGlossaryManager',
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'title, site_identifier, llm_config, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.language, source_language, formality, style_hint, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.glossary, glossary, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.scope, translate_tables, additional_fields, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.access, hidden',
        ],
    ],
];
