<?php
/*
 * This file is part of the "d_translate" extension for TYPO3 CMS.
 *
 * (c) 2026 Joachim Dreistein <info@dreistein.de>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

$EM_CONF[$_EXTKEY] = [
    'title' => 'dTRANSLATE – LLM Translation',
    'description' => 'LLM-based translation for TYPO3: translates pages and records into your site languages through TYPO3\'s own localize path (Anthropic Claude, OpenAI, Mistral or Google Gemini).',
    'category' => 'be',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-14.99.99',
        ],
        'conflicts' => [
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Dreistein\\DTranslate\\' => 'Classes',
        ],
    ],
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearCacheOnLoad' => 1,
    'author' => 'Joachim Dreistein',
    'author_email' => 'info@dreistein.de',
    'author_company' => 'Dreistein',
    'version' => '0.1.2',
];
