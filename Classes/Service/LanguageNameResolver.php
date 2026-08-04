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

namespace Dreistein\DTranslate\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Turns a site language into the language NAME the prompt talks about.
 *
 * Derived from the locale, not from the title: titles are free text ("DE",
 * "Hauptsprache", "v14test") and used to travel straight into the prompt as
 * "Translate from Hauptsprache into …". The title stays as a fallback for
 * setups without a usable locale.
 */
final class LanguageNameResolver
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /** Name of the default (source) language of the site a page belongs to. */
    public function sourceName(int $pageId): string
    {
        try {
            return $this->nameOf($this->siteFinder->getSiteByPageId($pageId)->getDefaultLanguage());
        } catch (SiteNotFoundException) {
            return '';
        }
    }

    /**
     * Name of a target language. Records may live in a folder outside any site
     * rootline, so the language id is looked up across sites as a fallback.
     */
    public function targetName(int $pageId, int $languageId): string
    {
        try {
            $name = $this->nameOf($this->siteFinder->getSiteByPageId($pageId)->getLanguageById($languageId));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable) {
            // fall through to the cross-site lookup
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                $name = $this->nameOf($site->getLanguageById($languageId));
                if ($name !== '') {
                    return $name;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return '';
    }

    public function nameOf(SiteLanguage $language): string
    {
        $locale = (string)$language->getLocale();
        if ($locale !== '' && class_exists(\Locale::class)) {
            // Displayed in English because the prompt itself is English.
            $name = \Locale::getDisplayLanguage($locale, 'en');
            if ($name !== '' && $name !== $locale) {
                return $name;
            }
        }
        return trim($language->getTitle());
    }
}
