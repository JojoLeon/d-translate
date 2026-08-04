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

use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Llm\ResolvedProfile;
use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Translation\ProcessingContext;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Translates explicitly listed columns of a translation record — beyond the
 * fields the localize hook covers. Handles plain text, RTE (HTML-preserving)
 * and FlexForm columns, always translating from the clean source record and
 * writing the result straight back.
 */
final class ColumnTranslator
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TranslationService $translationService,
        private readonly FlexFormTranslator $flexFormTranslator,
        private readonly LoggerInterface $logger,
        private readonly ProcessingContext $context,
    ) {
    }

    /**
     * @param list<string> $columns column names on $table to translate
     */
    public function translateColumns(
        string $table,
        int $translationUid,
        array $columns,
        ResolvedProfile $profile,
        string $targetLanguage,
        string $glossaryBlock,
        string $pageContext,
        bool $force = false
    ): void {
        $columns = array_values(array_unique(array_filter($columns)));
        if ($columns === [] || $translationUid <= 0) {
            return;
        }
        $translation = $this->row($table, $translationUid);
        if ($translation === null) {
            return;
        }
        $source = $this->sourceRecord($table, $translation);

        $update = [];
        $batch = [];
        $htmlFlags = [];
        foreach ($columns as $column) {
            $config = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? null;
            if ($config === null) {
                continue;
            }
            $value = (string)($source[$column] ?? '');
            if (trim($value) === '') {
                continue;
            }
            // Unless forced, skip fields that were already translated (non-empty
            // and different from the source). Empty translations are always
            // retranslated — the field may have been cleared by the editor.
            $translationValue = (string)($translation[$column] ?? '');
            if (!$force && $translationValue !== '' && $translationValue !== $value) {
                continue;
            }
            $type = (string)($config['type'] ?? '');
            try {
                if ($type === 'flex') {
                    $update[$column] = $this->flexFormTranslator->translate(
                        $value,
                        $table,
                        $column,
                        $source,
                        fn (string $v, bool $html): string => $this->translationService->translateField($v, $profile, $targetLanguage, $glossaryBlock, $pageContext, $html),
                    );
                } elseif (in_array($type, ['input', 'text'], true)) {
                    // Collected first, translated in one call below.
                    $batch[$column] = $value;
                    $htmlFlags[$column] = !empty($config['enableRichtext']);
                }
            } catch (\Throwable $e) {
                // Fatal provider errors (key, model, quota) end the run — the
                // module reports them; anything transient just skips the column.
                if ($e instanceof LlmRequestException && $e->fatal) {
                    throw $e;
                }
                $this->logger->error('d-translate: column translation failed', [
                    'table' => $table,
                    'uid' => $translationUid,
                    'field' => $column,
                    'exception' => $e->getMessage(),
                ]);
                $this->context->addError(sprintf('%s.%s: %s', $table, $column, $e->getMessage()));
            }
        }

        if ($batch !== []) {
            try {
                $update = array_merge($update, $this->translationService->translateFields(
                    $batch,
                    $profile,
                    $targetLanguage,
                    $glossaryBlock,
                    $pageContext,
                    $htmlFlags,
                ));
            } catch (\Throwable $e) {
                if ($e instanceof LlmRequestException && $e->fatal) {
                    throw $e;
                }
                $this->logger->error('d-translate: column batch failed', [
                    'table' => $table,
                    'uid' => $translationUid,
                    'fields' => array_keys($batch),
                    'exception' => $e->getMessage(),
                ]);
                $this->context->addError(sprintf('%s: %s', $table, $e->getMessage()));
            }
        }

        $update = $this->dropOverlongValues($table, $update);
        if ($update === []) {
            return;
        }
        try {
            $this->connectionPool->getConnectionForTable($table)->update($table, $update, ['uid' => $translationUid]);
        } catch (\Throwable $e) {
            // A rejected write is this record's problem, not the run's — report it
            // and let the remaining records finish.
            $this->logger->error('d-translate: writing the translation failed', [
                'table' => $table,
                'uid' => $translationUid,
                'fields' => array_keys($update),
                'exception' => $e->getMessage(),
            ]);
            $this->context->addError(sprintf('%s:%d: %s', $table, $translationUid, $e->getMessage()));
        }
    }

    /**
     * Keep values that exceed the field's declared maximum out of the write.
     * `header` and `title` are varchar(255): writing more aborts the query, and
     * with it the whole run. Leaving the source text in place is the lesser
     * evil, and the editor gets told which field it was.
     *
     * @param array<string, string> $update
     * @return array<string, string>
     */
    private function dropOverlongValues(string $table, array $update): array
    {
        foreach ($update as $column => $value) {
            $config = $GLOBALS['TCA'][$table]['columns'][$column]['config'] ?? [];
            $max = (int)($config['max'] ?? 0);
            if (($config['type'] ?? '') !== 'input' || $max <= 0) {
                continue;
            }
            $length = mb_strlen((string)$value);
            if ($length <= $max) {
                continue;
            }
            unset($update[$column]);
            $this->logger->warning('d-translate: translation exceeds the field length, column skipped', [
                'table' => $table,
                'field' => $column,
                'length' => $length,
                'max' => $max,
            ]);
            $this->context->addError(Messages::tr('field.tooLong', $table . '.' . $column, (string)$length, (string)$max));
        }
        return $update;
    }

    /**
     * @param array<string, mixed> $translation
     * @return array<string, mixed>
     */
    private function sourceRecord(string $table, array $translation): array
    {
        $transOrig = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        $sourceUid = $transOrig !== '' ? (int)($translation[$transOrig] ?? 0) : 0;
        if ($sourceUid > 0) {
            $source = $this->row($table, $sourceUid);
            if ($source !== null) {
                return $source;
            }
        }
        return $translation;
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }
}
