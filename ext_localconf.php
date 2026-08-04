<?php

declare(strict_types=1);
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

defined('TYPO3') or die();

use Dreistein\DTranslate\Backend\FormEngine\LlmEndpointDefaultWizard;
use Dreistein\DTranslate\Hook\CommandMapHook;
use Dreistein\DTranslate\Hook\TranslateFieldHook;
use Dreistein\DTranslate\Task\WorkQueueTask;

// Segment cache: identical source texts (labels, boilerplate) are translated
// once per profile+language instead of once per occurrence.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['dtranslate_segments'] ??= [
    'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
    'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 2592000],
    'groups' => ['system'],
];

// Backend languages the extension ships catalogues for but TYPO3 core does not
// list; without this the .xlf files are never looked up and the editor sees
// English. Registered additively, so a later core release simply wins.
foreach (['be' => 'Belarusian', 'cnr' => 'Montenegrin'] as $locale => $name) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['localization']['locales']['user'][$locale] ??= $name;
}

// FormEngine wizard on the LLM configuration record: pre-fills the API base URL
// for the selected provider type.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'][1785600000] = [
    'nodeName' => 'dtranslateLlmEndpointDefault',
    'priority' => 40,
    'class' => LlmEndpointDefaultWizard::class,
];

// Per-field translation: DataHandler calls this for every translatable field
// during a "localize" command. This is the single field-level entry point.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processTranslateToClass'][]
    = TranslateFieldHook::class;

// Command map: captures the table/uid currently being localized into the
// request-scoped ProcessingContext, because the field hook above does NOT
// receive the table name in its arguments.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][]
    = CommandMapHook::class;

// Background worker as a scheduler task, for installations that run the
// scheduler anyway. Equivalent to the console command `dtranslate:work`; use
// one or the other, not both.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][WorkQueueTask::class] = [
    'extension' => 'd_translate',
    'title' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang.xlf:task.work.title',
    'description' => 'LLL:EXT:d_translate/Resources/Private/Language/locallang.xlf:task.work.description',
];
