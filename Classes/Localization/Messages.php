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

namespace Dreistein\DTranslate\Localization;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Single access point to the extension's message catalogue.
 *
 * Static on purpose: the callers are exception formatters, wire clients and
 * DataHandler hooks — places where threading a LanguageService through the
 * constructor buys nothing. Resolves against the backend user's language and
 * falls back to the English source when a catalogue lacks the id.
 */
final class Messages
{
    private const FILE = 'LLL:EXT:d_translate/Resources/Private/Language/locallang.xlf:';
    private const FILE_DB = 'LLL:EXT:d_translate/Resources/Private/Language/locallang_db.xlf:';

    private static ?LanguageService $languageService = null;

    /** @param int|float|string ...$args sprintf arguments, in source order */
    public static function tr(string $key, int|float|string ...$args): string
    {
        $text = self::languageService()->sL(self::FILE . $key);
        if ($text === '') {
            // Catalogue missing (e.g. a stripped install) — the id is still
            // more useful than an empty message.
            $text = $key;
        }
        return $args === [] ? $text : vsprintf($text, $args);
    }

    /**
     * Label from the TCA catalogue. Needed wherever code produces something the
     * TCA would normally translate itself — e.g. items built in an itemsProcFunc.
     */
    public static function db(string $key): string
    {
        $text = self::languageService()->sL(self::FILE_DB . $key);
        return $text !== '' ? $text : $key;
    }

    private static function languageService(): LanguageService
    {
        if (self::$languageService instanceof LanguageService) {
            return self::$languageService;
        }
        $lang = $GLOBALS['LANG'] ?? null;
        if (!$lang instanceof LanguageService) {
            $lang = GeneralUtility::makeInstance(LanguageServiceFactory::class)
                ->createFromUserPreferences($GLOBALS['BE_USER'] ?? null);
        }
        return self::$languageService = $lang;
    }

    /** Test seam / long-running CLI processes that switch the backend user. */
    public static function reset(): void
    {
        self::$languageService = null;
    }
}
