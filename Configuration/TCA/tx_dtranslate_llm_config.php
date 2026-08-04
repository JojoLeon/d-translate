<?php

declare(strict_types=1);

return [
    'ctrl' => [
        'title' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config',
        'label' => 'title',
        'label_alt' => 'type,chat_model',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'default_sortby' => 'title',
        'rootLevel' => -1,
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/actions/actions-cog.svg',
        'searchFields' => 'title,type,chat_model',
    ],
    'columns' => [
        'hidden' => [
            'config' => ['type' => 'check', 'renderType' => 'checkboxToggle', 'default' => 0],
        ],
        'title' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.title',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim', 'required' => true],
        ],
        'type' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.type.anthropic', 'value' => 'anthropic'],
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.type.gemini', 'value' => 'gemini'],
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.type.mistral', 'value' => 'mistral'],
                    ['label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.type.openai', 'value' => 'openai'],
                ],
                'default' => 'anthropic',
                'fieldWizard' => [
                    'llmEndpointDefault' => [
                        'renderType' => 'dtranslateLlmEndpointDefault',
                    ],
                ],
            ],
        ],
        'endpoint' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.endpoint',
            'config' => ['type' => 'input', 'size' => 40, 'eval' => 'trim', 'placeholder' => 'https://api.anthropic.com/v1'],
        ],
        'chat_model' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.chat_model',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim', 'placeholder' => 'claude-sonnet-4-6'],
        ],
        'api_key' => [
            'label' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.api_key',
            'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tx_dtranslate_llm_config.api_key.description',
            'config' => [
                'type' => 'password',
                'size' => 40,
                'nullable' => false,
                // Without this TYPO3 stores an argon2i HASH of the key and every
                // request is rejected with "401 Incorrect API key provided".
                'hashed' => false,
            ],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'title, type, chat_model, endpoint, api_key, --div--;LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:tabs.access, hidden'],
    ],
];
