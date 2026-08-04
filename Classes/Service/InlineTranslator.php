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
use Dreistein\DTranslate\Translation\ProcessingContext;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Translates inline (IRRE / ContentBlocks) children of a freshly-localized
 * record. The DataHandler copy hook only fires for fields with the
 * PrefixLanguageTitle behaviour; ContentBlocks/synchronized inline fields are
 * copied verbatim and never reach the hook. This pass walks the TCA inline
 * relations of a translated record, translates each child's prose fields, and
 * writes them back via a DataHandler datamap (which does not re-enter the
 * localize hook). Recurses into nested inline structures.
 */
final class InlineTranslator
{
    private const MAX_DEPTH = 5;

    /**
     * Translatable prose fields on a FAL reference. A strict allowlist — NEVER
     * touch technical fields like tablenames/fieldname/uid_local.
     */
    private const FILE_REFERENCE_FIELDS = ['title', 'alternative', 'description'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TranslationService $translationService,
        private readonly SourceVisibility $sourceVisibility,
        private readonly FieldPolicy $fieldPolicy,
        private readonly LoggerInterface $logger,
        private readonly ProcessingContext $context,
    ) {
    }

    /** @return int number of child fields translated */
    public function translateChildren(
        string $parentTable,
        int $translatedParentUid,
        ResolvedProfile $profile,
        string $targetLanguage,
        string $glossaryBlock,
        string $pageContext,
        int $targetLanguageId,
        int $depth = 0
    ): int {
        if ($depth >= self::MAX_DEPTH) {
            return 0;
        }
        // Inline children may point their foreign_field at either the translated
        // parent (e.g. ContentBlocks collections) or the default parent (e.g.
        // language-synchronized relations) — match both.
        $parentTransOrig = (string)($GLOBALS['TCA'][$parentTable]['ctrl']['transOrigPointerField'] ?? '');
        $parentUids = [$translatedParentUid];
        if ($parentTransOrig !== '') {
            $parentRow = $this->row($parentTable, $translatedParentUid);
            $defaultParentUid = (int)($parentRow[$parentTransOrig] ?? 0);
            if ($defaultParentUid > 0) {
                $parentUids[] = $defaultParentUid;
            }
        }
        $parentUids = array_values(array_unique($parentUids));

        $translated = 0;
        foreach ($this->inlineFields($parentTable) as $config) {
            $childTable = (string)$config['foreign_table'];
            $foreignField = (string)$config['foreign_field'];
            $childLanguageField = (string)($GLOBALS['TCA'][$childTable]['ctrl']['languageField'] ?? '');
            if ($childLanguageField === '') {
                continue;
            }
            foreach ($this->childUids($childTable, $foreignField, $parentUids, $childLanguageField, $targetLanguageId, $config['foreign_match_fields']) as $childUid) {
                $translated += $this->translateChildRecord($childTable, $childUid, $profile, $targetLanguage, $glossaryBlock, $pageContext);
                // A child ends up as visible — or as hidden — as the child it was
                // translated from. It used to be forced visible, which quietly
                // published accordion items the editor had switched off.
                $this->sourceVisibility->mirror($childTable, $childUid);
                // Recurse into the child's own inline relations.
                $translated += $this->translateChildren($childTable, $childUid, $profile, $targetLanguage, $glossaryBlock, $pageContext, $targetLanguageId, $depth + 1);
            }
        }

        // FAL references of this record (any field — column or FlexForm-embedded):
        // translate the per-reference metadata (allowlisted fields only).
        foreach ($this->fileReferenceUids($parentTable, $parentUids, $targetLanguageId) as $refUid) {
            $translated += $this->translateFileReference($refUid, $profile, $targetLanguage, $glossaryBlock, $pageContext);
            $this->sourceVisibility->mirror('sys_file_reference', $refUid);
        }

        return $translated;
    }

    /**
     * All localized sys_file_reference uids attached to the given parent record,
     * regardless of which field (column or FlexForm) holds them.
     *
     * @param list<int> $parentUids
     * @return list<int>
     */
    private function fileReferenceUids(string $parentTable, array $parentUids, int $targetLanguageId): array
    {
        if ($parentUids === [] || !isset($GLOBALS['TCA']['sys_file_reference'])) {
            return [];
        }
        $languageField = (string)($GLOBALS['TCA']['sys_file_reference']['ctrl']['languageField'] ?? 'sys_language_uid');
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->in('uid_foreign', $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($parentTable)),
                $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Translate the allowlisted prose metadata of one FAL reference
     * (title / alternative / description). Technical fields are never touched.
     */
    private function translateFileReference(int $refUid, ResolvedProfile $profile, string $targetLanguage, string $glossaryBlock, string $pageContext): int
    {
        $translation = $this->row('sys_file_reference', $refUid);
        if ($translation === null) {
            return 0;
        }
        $source = $this->sourceRecord('sys_file_reference', $translation);
        $datamap = [];
        foreach (self::FILE_REFERENCE_FIELDS as $column) {
            if (!isset($GLOBALS['TCA']['sys_file_reference']['columns'][$column])) {
                continue;
            }
            $value = (string)($source[$column] ?? '');
            if (trim($value) === '') {
                continue;
            }
            try {
                $datamap[$column] = $this->translationService->translateField($value, $profile, $targetLanguage, $glossaryBlock, $pageContext, false);
            } catch (\Throwable $e) {
                // Fatal provider errors (key, model, quota) abort the run — the
                // module turns them into a message the editor can act on.
                if ($e instanceof LlmRequestException && $e->fatal) {
                    throw $e;
                }
                $this->logger->error('d-translate: file reference metadata translation failed', [
                    'uid' => $refUid,
                    'field' => $column,
                    'exception' => $e->getMessage(),
                ]);
                $this->context->addError(sprintf('sys_file_reference.%s: %s', $column, $e->getMessage()));
            }
        }
        if ($datamap === []) {
            return 0;
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_file_reference' => [$refUid => $datamap]], []);
        $dataHandler->process_datamap();
        return count($datamap);
    }

    private function translateChildRecord(
        string $childTable,
        int $childUid,
        ResolvedProfile $profile,
        string $targetLanguage,
        string $glossaryBlock,
        string $pageContext
    ): int {
        $translation = $this->row($childTable, $childUid);
        if ($translation === null) {
            return 0;
        }
        // Translate from the clean SOURCE record (not the just-localized copy,
        // which may carry a "[Translate to …]" prefix) and overwrite.
        $source = $this->sourceRecord($childTable, $translation);
        $datamap = [];
        $batch = [];
        $htmlFlags = [];
        foreach (array_keys($GLOBALS['TCA'][$childTable]['columns'] ?? []) as $column) {
            if (!$this->fieldPolicy->shouldTranslate($childTable, (string)$column)) {
                continue;
            }
            $value = (string)($source[$column] ?? '');
            if (trim($value) === '') {
                continue;
            }
            $batch[(string)$column] = $value;
            $htmlFlags[(string)$column] = $this->fieldPolicy->format($childTable, (string)$column) === FieldPolicy::FORMAT_HTML;
        }
        if ($batch !== []) {
            try {
                // One call for the whole child record instead of one per field.
                $datamap = array_merge($datamap, $this->translationService->translateFields(
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
                $this->logger->error('d-translate: inline child translation failed', [
                    'table' => $childTable,
                    'uid' => $childUid,
                    'fields' => array_keys($batch),
                    'exception' => $e->getMessage(),
                ]);
                $this->context->addError(sprintf('%s: %s', $childTable, $e->getMessage()));
            }
        }
        if ($datamap === []) {
            return 0;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$childTable => [$childUid => $datamap]], []);
        $dataHandler->process_datamap();

        return count($datamap);
    }

    /**
     * Every IRRE/inline relation of a table: any column with type=inline and a
     * foreign_table + foreign_field. Not specific to any content type — works
     * for ContentBlocks collections and classic IRRE alike.
     *
     * @return list<array{foreign_table: string, foreign_field: string, foreign_match_fields: array<string, mixed>}>
     */
    private function inlineFields(string $table): array
    {
        $fields = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $config) {
            $c = $config['config'] ?? [];
            if (($c['type'] ?? '') === 'inline'
                && !empty($c['foreign_table'])
                && !empty($c['foreign_field'])
            ) {
                $fields[] = [
                    'foreign_table' => (string)$c['foreign_table'],
                    'foreign_field' => (string)$c['foreign_field'],
                    // Disambiguates child tables shared by several relations.
                    'foreign_match_fields' => (array)($c['foreign_match_fields'] ?? []),
                ];
            }
        }
        return $fields;
    }

    /**
     * @param list<int>            $parentUids   translated and/or default parent uid
     * @param array<string, mixed> $matchFields  foreign_match_fields constraints
     * @return list<int>
     */
    private function childUids(string $childTable, string $foreignField, array $parentUids, string $languageField, int $targetLanguageId, array $matchFields): array
    {
        if ($parentUids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($childTable);
        $qb->getRestrictions()->removeAll();
        // No pid restriction — inline children may live on a different page/folder.
        $constraints = [
            $qb->expr()->in($foreignField, $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
            $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
            $qb->expr()->eq('deleted', 0),
        ];
        foreach ($matchFields as $field => $value) {
            $constraints[] = $qb->expr()->eq($field, $qb->createNamedParameter((string)$value));
        }
        $rows = $qb->select('uid')
            ->from($childTable)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * The default-language source of a translation record (via its
     * transOrigPointer), or the record itself when there is none.
     *
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
