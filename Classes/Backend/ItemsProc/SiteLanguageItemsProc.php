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
use Dreistein\DTranslate\Service\LanguageNameResolver;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the glossary's "applies to target language" select with every
 * language of every configured site.
 *
 * A glossary entry is scoped by sys_language_uid, and that number is only
 * meaningful together with a site — the same id can be Spanish in one site and
 * Polish in another. The list therefore groups by site and spells out both the
 * language name and its id, instead of asking editors to know the number.
 */
final class SiteLanguageItemsProc
{
    /** @param array{items: array<int, array<int, mixed>>} $params */
    public function populate(array &$params): void
    {
        $params['items'][] = ['label' => Messages::db('tx_dtranslate_glossary.target_language.all'), 'value' => -1];

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $names = GeneralUtility::makeInstance(LanguageNameResolver::class, $siteFinder);
        $sites = $siteFinder->getAllSites();

        foreach ($sites as $site) {
            $languages = [];
            foreach ($site->getAllLanguages() as $language) {
                // The default language is the source, never a translation target.
                if ($language->getLanguageId() === 0) {
                    continue;
                }
                $languages[] = $language;
            }
            if ($languages === []) {
                continue;
            }
            // Only worth a header when more than one site is configured.
            if (count($sites) > 1) {
                $params['items'][] = ['label' => $site->getIdentifier(), 'value' => '--div--'];
            }
            foreach ($languages as $language) {
                $name = $names->nameOf($language);
                $params['items'][] = [
                    'label' => sprintf('%s (#%d)', $name !== '' ? $name : $language->getTitle(), $language->getLanguageId()),
                    'value' => $language->getLanguageId(),
                ];
            }
        }
    }
}
