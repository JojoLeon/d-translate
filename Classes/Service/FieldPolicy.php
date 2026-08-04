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

/**
 * Decides, from the TCA, whether a field should be machine-translated and in
 * which format. DataHandler already filters to localizable fields; this adds
 * the d-translate judgement the hook arguments cannot give: skip non-text and
 * numeric/identifier fields, and distinguish RTE (HTML) from plain text so the
 * prompt can preserve markup.
 */
final class FieldPolicy
{
    public const FORMAT_PLAIN = 'plain';
    public const FORMAT_HTML = 'html';

    /** eval flags that mark a value as non-prose (numbers, dates, hashes, …). */
    private const NUMERIC_EVALS = ['int', 'double2', 'num', 'md5', 'password', 'is_in', 'date', 'datetime', 'time', 'timesec', 'year'];

    /** input field names that hold identifiers/links/dates, never prose. */
    private const TECHNICAL_NAME = '/(?:^|_)(e?mail|url|uri|href|slug|guid|uuid|ident|identifier|hash|token|colou?r|date|time|datetime|year|code)(?:_|$)/i';
    private const TECHNICAL_SUFFIX = '/(email|url|uri|slug|guid|uuid|date|time)$/i';

    /**
     * Single-line inputs that are prose all the same, so the blanket "no
     * inputs" rule below must not swallow them.
     *
     * tt_content.subheader is the standard sub-headline next to the header.
     * It is neither the label column nor a prefixLangTitle field, so nothing
     * else picked it up and translations kept the source wording — while the
     * header right above it was translated, which looks like a plain bug to
     * an editor. shouldTranslate() has always considered it safe; only the
     * auto list disagreed.
     *
     * @var array<string, list<string>>
     */
    private const PROSE_INPUTS = [
        'tt_content' => ['subheader'],
    ];

    /**
     * Columns auto-translated on a record: multiline text/RTE fields (prose)
     * that are not language-excluded or numeric, plus the few single-line
     * inputs listed in PROSE_INPUTS. Other input fields are deliberately NOT
     * auto-translated — prefix-title inputs are handled by the localize hook,
     * and the rest (names, agencies, links) are too easily mangled; add those
     * explicitly via the profile's additional fields.
     *
     * @return list<string>
     */
    public function autoTranslatableColumns(string $table): array
    {
        $columns = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column => $definition) {
            $config = $definition['config'] ?? [];
            $type = (string)($config['type'] ?? '');
            $l10nMode = (string)($definition['l10n_mode'] ?? '');
            if ($l10nMode === 'exclude') {
                continue;
            }
            $evals = array_filter(array_map('trim', explode(',', (string)($config['eval'] ?? ''))));
            if (array_intersect($evals, self::NUMERIC_EVALS) !== []) {
                continue;
            }
            // Multiline text/RTE is prose; a single-line input is only included
            // when it is a prefix-title field (so a re-translation can refresh
            // the title the localize hook would otherwise handle on first run)
            // or known prose like tt_content.subheader.
            // FlexForm columns (pi_flexform, ContentBlocks) are translated field-
            // by-field by FlexFormTranslator — include them explicitly.
            $isProseInput = $type === 'input' && (
                $l10nMode === 'prefixLangTitle'
                || in_array((string)$column, self::PROSE_INPUTS[$table] ?? [], true)
            );
            if ($type === 'text' || $type === 'flex' || $isProseInput) {
                $columns[] = (string)$column;
            }
        }

        // The label column is the record's human-readable title, so it is prose
        // even when it is a plain input without prefixLangTitle — sys_category
        // and many extension tables are built that way, and their translations
        // used to keep the source title. shouldTranslate() still keeps slugs,
        // links, dates and widget fields out.
        $label = (string)($GLOBALS['TCA'][$table]['ctrl']['label'] ?? '');
        if ($label !== '' && !in_array($label, $columns, true) && $this->shouldTranslate($table, $label)) {
            $columns[] = $label;
        }

        return $columns;
    }

    /**
     * Whether (table, column) resolves to a TCA field. False for inline-child
     * fields whose real table the localize hook cannot report — those are
     * still translated (the core only invokes the hook for prose fields), just
     * with a heuristic format instead of a TCA lookup.
     */
    public function isKnownField(string $table, string $column): bool
    {
        return $this->config($table, $column) !== null;
    }

    public function shouldTranslate(string $table, string $column): bool
    {
        $config = $this->config($table, $column);
        if ($config === null) {
            return false;
        }
        // l10n_mode lives on the column level, not inside config.
        if (($GLOBALS['TCA'][$table]['columns'][$column]['l10n_mode'] ?? '') === 'exclude') {
            return false;
        }
        $type = (string)($config['type'] ?? '');
        if (!in_array($type, ['input', 'text'], true)) {
            return false;
        }
        $evals = array_filter(array_map('trim', explode(',', (string)($config['eval'] ?? ''))));
        if (array_intersect($evals, self::NUMERIC_EVALS) !== []) {
            return false;
        }
        // Multiline text is prose. Single-line inputs are only translated when
        // they are neither a special widget nor an identifier/link/date field
        // (those would be corrupted by translation).
        if ($type === 'input') {
            if (!empty($config['renderType'])) {
                return false;
            }
            if (preg_match(self::TECHNICAL_NAME, $column) || preg_match(self::TECHNICAL_SUFFIX, $column)) {
                return false;
            }
        }
        return true;
    }

    public function format(string $table, string $column): string
    {
        $config = $this->config($table, $column);
        if ($config !== null && ($config['type'] ?? '') === 'text' && !empty($config['enableRichtext'])) {
            return self::FORMAT_HTML;
        }
        return self::FORMAT_PLAIN;
    }

    /** @return array<string, mixed>|null */
    private function config(string $table, string $column): ?array
    {
        $config = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? null;
        return is_array($config) ? $config : null;
    }
}
