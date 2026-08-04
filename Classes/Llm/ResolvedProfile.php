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

namespace Dreistein\DTranslate\Llm;

/**
 * Everything a translation run needs once a site/profile has been resolved:
 * the bound LLM plus the editorial settings (source language, formality, style
 * hint, table allowlist). Immutable; produced by
 * {@see \Dreistein\DTranslate\Service\ProfileLlmResolver}.
 */
final class ResolvedProfile
{
    /** @param list<string> $translateTables empty = allow any localizable table */
    public function __construct(
        public readonly ResolvedLlm $llm,
        public readonly string $sourceLanguage,
        public readonly string $formality = 'default',
        public readonly string $styleHint = '',
        public readonly array $translateTables = [],
        public readonly int $profileUid = 0,
        /** @var list<array{table: string, column: string}> extra columns to translate */
        public readonly array $additionalFields = [],
    ) {
    }

    /** @return list<string> column names configured for the given table */
    public function additionalColumnsFor(string $table): array
    {
        $columns = [];
        foreach ($this->additionalFields as $field) {
            if ($field['table'] === $table) {
                $columns[] = $field['column'];
            }
        }
        return $columns;
    }

    public function allowsTable(string $table): bool
    {
        return $this->translateTables === [] || in_array($table, $this->translateTables, true);
    }
}
