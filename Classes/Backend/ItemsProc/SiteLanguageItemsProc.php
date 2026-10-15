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

namespace Dreistein\DTranslate\Backend\ItemsProc;

use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Service\LanguageCatalog;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the glossary's "applies to" select: every target language, then
 * the languages (one entry covers every regional variant), the regional
 * variants, and finally the single site language ids — each listed once,
 * however many sites share it. Ids are what entries were scoped by before
 * 0.2; they stay selectable so nothing an editor set up changes meaning.
 */
final class SiteLanguageItemsProc
{
    /** @param array{items: array<int, array<string, mixed>>} $params */
    public function populate(array &$params): void
    {
        $catalog = GeneralUtility::makeInstance(LanguageCatalog::class, GeneralUtility::makeInstance(SiteFinder::class));
        $catalogue = $catalog->catalogue();

        $params['items'][] = ['label' => Messages::db('tx_dtranslate_glossary.target_language.all'), 'value' => LanguageCatalog::ALL];

        $groups = [
            ['tx_dtranslate_glossary.target_language.languages', $catalogue['languages']],
            ['tx_dtranslate_glossary.target_language.variants', $catalogue['variants']],
            ['tx_dtranslate_glossary.target_language.ids', $catalogue['ids']],
        ];
        foreach ($groups as [$labelKey, $entries]) {
            if ($entries === []) {
                continue;
            }
            $params['items'][] = ['label' => Messages::db($labelKey), 'value' => '--div--'];
            foreach (array_keys($entries) as $key) {
                $params['items'][] = ['label' => $catalog->label((string)$key), 'value' => (string)$key];
            }
        }
    }
}
