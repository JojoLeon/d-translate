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

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * What a glossary entry can apply to, and what a target language answers to.
 *
 * An entry's `target_language` holds one key:
 *   '-1'     every target language
 *   'en'     a language — every site language whose locale is English
 *   'en-GB'  a regional variant — every site language with that locale
 *   '15'     one site language id, the way entries were scoped before 0.2
 *
 * A target language answers to all four, most specific first; the store uses
 * that order when two entries name the same source term. Keys come from the
 * locale of the site language, so an entry made on one site applies to every
 * site that has the language — and old numeric entries keep working unchanged.
 */
final class LanguageCatalog
{
    public const ALL = '-1';

    /** @var array{languages: array<string, string>, variants: array<string, string>, ids: array<int, string>}|null */
    private ?array $catalogue = null;

    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * Keys an entry may carry to apply to this site language, most specific
     * first: id, regional variant, language, all.
     *
     * @return list<string>
     */
    public function keysFor(SiteLanguage $language): array
    {
        $locale = $language->getLocale();
        $keys = [(string)$language->getLanguageId()];
        if ($locale->getName() !== $locale->getLanguageCode()) {
            $keys[] = $locale->getName();
        }
        if ($locale->getLanguageCode() !== '') {
            $keys[] = $locale->getLanguageCode();
        }
        $keys[] = self::ALL;
        return $keys;
    }

    /**
     * Keys for a target language of the page's site. Records in a folder
     * outside any site rootline fall back to the first site that has the id.
     *
     * @return list<string>
     */
    public function keysForTarget(int $pageId, int $languageId): array
    {
        $language = $this->siteLanguage($pageId, $languageId);
        return $language !== null ? $this->keysFor($language) : [(string)$languageId, self::ALL];
    }

    /** 3 = site language id, 2 = regional variant, 1 = language, 0 = every language. */
    public static function specificity(string $key): int
    {
        $key = trim($key);
        if ($key === '' || $key === self::ALL) {
            return 0;
        }
        if (ctype_digit($key)) {
            return 3;
        }
        return str_contains($key, '-') ? 2 : 1;
    }

    /**
     * The language code a stored key belongs to: 'en' for 'en', 'en-GB' and
     * for an id whose locale is English; '' for "every language" and ids no
     * site knows any more.
     */
    public function languageCodeOf(string $key): string
    {
        $key = trim($key);
        if (self::specificity($key) === 0) {
            return '';
        }
        if (ctype_digit($key)) {
            $language = $this->siteLanguage(0, (int)$key);
            return $language !== null ? $language->getLocale()->getLanguageCode() : '';
        }
        return strtolower(explode('-', $key, 2)[0]);
    }

    /**
     * The regional variant behind a key: 'en-GB' for 'en-GB' and for an id
     * whose locale is en-GB; the bare language for a variant-less locale; ''
     * for "every language" and ids no site knows.
     */
    public function variantOf(string $key): string
    {
        $key = trim($key);
        return match (self::specificity($key)) {
            0 => '',
            3 => (string)($this->siteLanguage(0, (int)$key)?->getLocale()->getName() ?? ''),
            default => $key,
        };
    }

    /** Regional variant keys (e.g. 'en-GB', 'en-US') the installation has for a language code. @return list<string> */
    public function variantsOf(string $languageCode): array
    {
        $variants = [];
        foreach (array_keys($this->catalogue()['variants']) as $name) {
            if ($this->languageCodeOf($name) === $languageCode) {
                $variants[] = $name;
            }
        }
        return $variants;
    }

    /**
     * Everything a target language can be, deduplicated over all sites, the
     * default languages left out (they are the source, never a target).
     *
     * @return array{languages: array<string, string>, variants: array<string, string>, ids: array<int, string>}
     */
    public function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }
        $languages = [];
        $variants = [];
        $ids = [];
        $uiLocale = $this->uiLocale();
        foreach ($this->siteFinder->getAllSites() as $site) {
            $defaultId = $site->getDefaultLanguage()->getLanguageId();
            foreach ($site->getAllLanguages() as $language) {
                $id = $language->getLanguageId();
                if ($id === $defaultId) {
                    continue;
                }
                $locale = $language->getLocale();
                $code = $locale->getLanguageCode();
                $title = trim($language->getTitle());
                if ($code !== '' && !isset($languages[$code])) {
                    $languages[$code] = $this->languageName($code, $uiLocale);
                }
                if ($locale->getName() !== $code && !isset($variants[$locale->getName()])) {
                    $variants[$locale->getName()] = $title !== '' ? $title : $locale->getName();
                }
                if (!isset($ids[$id])) {
                    $ids[$id] = $title !== '' ? $title : $this->languageName($code, $uiLocale);
                }
            }
        }
        asort($languages, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($variants, SORT_NATURAL | SORT_FLAG_CASE);
        ksort($ids);
        return $this->catalogue = ['languages' => $languages, 'variants' => $variants, 'ids' => $ids];
    }

    /** Human-readable form of a stored key, for lists and select boxes. */
    public function label(string $key): string
    {
        $key = trim($key);
        $catalogue = $this->catalogue();
        return match (self::specificity($key)) {
            0 => '',
            3 => isset($catalogue['ids'][(int)$key])
                ? sprintf('%s (#%d)', $catalogue['ids'][(int)$key], (int)$key)
                : sprintf('#%d', (int)$key),
            2 => sprintf('%s (%s)', $catalogue['variants'][$key] ?? $key, $key),
            default => sprintf('%s (%s)', $catalogue['languages'][$key] ?? $this->languageName($key, $this->uiLocale()), $key),
        };
    }

    private function siteLanguage(int $pageId, int $languageId): ?SiteLanguage
    {
        if ($pageId > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($pageId)->getLanguageById($languageId);
            } catch (\Throwable) {
                // fall through to the cross-site lookup
            }
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                return $site->getLanguageById($languageId);
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /** "English" / "Englisch", following the backend user's language. */
    private function languageName(string $code, string $uiLocale): string
    {
        if ($code !== '' && class_exists(\Locale::class)) {
            $name = \Locale::getDisplayLanguage($code, $uiLocale);
            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }
        return $code;
    }

    private function uiLocale(): string
    {
        $lang = $GLOBALS['LANG'] ?? null;
        $code = $lang instanceof LanguageService ? (string)$lang->lang : (string)($GLOBALS['BE_USER']->user['lang'] ?? '');
        return $code === '' || $code === 'default' ? 'en' : $code;
    }
}
