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

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Points the anchors of page links at the translated content element.
 *
 * A link to a content element is stored as `t3://page?uid=<page>#<uid>` (the
 * link browser) or `t3://page?uid=<page>#c<uid>` (typed by hand), where <uid>
 * is the DEFAULT-language element. DataHandler copies such links verbatim into
 * the translation and the model is told to leave URLs alone, so the anchor of a
 * translated link still names the source element. On a site that renders its
 * translations in free mode (`fallbackType: free`) the translated page knows
 * nothing of that uid — `id="c<uid>"` carries the translated element's own uid —
 * and the jump quietly ends at the top of the page. In overlay mode both ids
 * are rendered, so the translated uid is right there too.
 *
 * The anchor is remapped through the element's translation pointer
 * (l18n_parent, or l10n_source for an element copied into the language). The
 * link target is the container or element itself, so a child's
 * tx_container_parent has nothing to add here. An anchor whose element has no
 * translation in the target language is left alone.
 *
 * remapPage() runs after every translation run; remapOnPages() is the one-off
 * repair of everything on a set of pages (`dtranslate:remap-anchors`). Both
 * can run dry and report what they found through takeChanges().
 */
final class AnchorLinkRemapper
{
    private const CONTENT_TABLE = 'tt_content';

    /**
     * A page link with an element anchor: `t3://page?uid=12[&…]#34` or `#c34`.
     * Group 1 runs up to and including the '#', group 2 is the optional 'c',
     * group 3 the element uid. The trailing lookahead keeps `#345` from being
     * read as `#34`.
     */
    private const PATTERN = '~(t3://page\?uid=\d+[^#\s"\'<>]*#)(c?)(\d+)(?!\d)~';

    /** @var array<string, int> "<uid>|<language>" => translated element uid (0 = none) */
    private array $anchorCache = [];

    /** @var array<int, array<string, mixed>|null> */
    private array $rowCache = [];

    /**
     * What remapRecords() changed (or, in a dry run, would change) since the
     * report was last taken: one entry per record, the remapped anchors per
     * column as [old, new] pairs — and, per column, the anchors it had to
     * leave alone because the element has no translation in the language
     * ('untranslated') or does not exist ('missing'). A record with nothing
     * but anchors left alone is listed too; it does not count as changed.
     *
     * `page` is the default-language page the record is rendered on (its pid,
     * or the page it translates): the page cache to clear after a direct write.
     *
     * @var list<array{table: string, uid: int, pid: int, page: int, language: int, columns: array<string, list<array{0: string, 1: string}>>, unresolved: array<string, list<array{0: string, 1: string}>>}>
     */
    private array $changes = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Remap the anchors of everything in the target language that belongs to
     * a page: the page translation itself, every translation record of the
     * given tables on the page — not only the ones a run just created, since
     * an earlier translation may link to an element translated just now — and
     * the records elsewhere that link to an anchor on this page.
     *
     * @param list<string> $tables localizable tables whose records live on the page
     * @param bool $dryRun report the changes (see takeChanges()) but write nothing
     * @return int number of records changed
     */
    public function remapPage(int $pageId, int $targetLanguageId, array $tables, bool $dryRun = false): int
    {
        if ($pageId <= 0 || $targetLanguageId <= 0) {
            return 0;
        }
        $tables = array_values(array_unique(array_merge(['pages', self::CONTENT_TABLE], $tables)));
        $changed = 0;
        foreach ($tables as $table) {
            $changed += $this->remapRecords($table, $this->translationsOnPage($table, $pageId, $targetLanguageId), $targetLanguageId, $dryRun);
        }
        $changed += $this->remapLinksToPage($pageId, $targetLanguageId, $tables, $dryRun);
        return $changed;
    }

    /**
     * Remap the anchors of every record in the target language on the given
     * pages, whatever its table: the one-off repair of translations made
     * before the remapper existed, or of a site filled by an import. One query
     * per table and chunk of pages instead of one per page.
     *
     * @param list<int> $pageIds default-language page uids
     * @param bool $dryRun report the changes (see takeChanges()) but write nothing
     * @return int number of records changed
     */
    public function remapOnPages(array $pageIds, int $targetLanguageId, bool $dryRun = false): int
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        if ($pageIds === [] || $targetLanguageId <= 0) {
            return 0;
        }
        $changed = 0;
        foreach ($this->linkBearingTables() as $table) {
            foreach (array_chunk($pageIds, 1000) as $chunk) {
                $uids = $this->linkingRecordsOnPages($table, $chunk, $targetLanguageId);
                $changed += $this->remapRecords($table, $uids, $targetLanguageId, $dryRun);
            }
        }
        return $changed;
    }

    /**
     * The changes made (or, in a dry run, found) since the last call; the
     * report starts afresh afterwards.
     *
     * @return list<array{table: string, uid: int, pid: int, page: int, language: int, columns: array<string, list<array{0: string, 1: string}>>, unresolved: array<string, list<array{0: string, 1: string}>>}>
     */
    public function takeChanges(): array
    {
        $changes = $this->changes;
        $this->changes = [];
        return $changes;
    }

    /**
     * Rewrite the anchors in every TCA column of the given translation records.
     *
     * @param list<int> $uids records of $table in the target language
     * @param bool $dryRun report the changes (see takeChanges()) but write nothing
     * @return int number of records changed
     */
    public function remapRecords(string $table, array $uids, int $targetLanguageId, bool $dryRun = false): int
    {
        $columns = array_keys($GLOBALS['TCA'][$table]['columns'] ?? []);
        if ($uids === [] || $columns === [] || $targetLanguageId <= 0) {
            return 0;
        }
        $connection = $this->connectionPool->getConnectionForTable($table);
        $changed = 0;
        foreach ($uids as $uid) {
            $row = $this->row($table, (int)$uid);
            if ($row === null) {
                continue;
            }
            $update = [];
            $anchors = [];
            $unresolved = [];
            // Only TCA columns: the diff source (l10n_diffsource) is a serialized
            // copy of the source record whose string lengths must not change.
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (!is_string($value) || !str_contains($value, 't3://page?uid=')) {
                    continue;
                }
                $pairs = [];
                $leftAlone = [];
                $rewritten = $this->rewriteCollecting($value, $targetLanguageId, $pairs, $leftAlone);
                if ($rewritten !== $value) {
                    $update[$column] = $rewritten;
                    $anchors[$column] = $pairs;
                }
                if ($leftAlone !== []) {
                    $unresolved[$column] = $leftAlone;
                }
            }
            if ($update === [] && $unresolved === []) {
                continue;
            }
            $parentField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
            $this->changes[] = [
                'table' => $table,
                'uid' => (int)$uid,
                'pid' => (int)($row['pid'] ?? 0),
                'page' => $table === 'pages' && $parentField !== '' ? (int)($row[$parentField] ?? 0) : (int)($row['pid'] ?? 0),
                'language' => $targetLanguageId,
                'columns' => $anchors,
                'unresolved' => $unresolved,
            ];
            if ($update === []) {
                continue;
            }
            $changed++;
            if ($dryRun) {
                continue;
            }
            $connection->update($table, $update, ['uid' => (int)$uid]);
            $this->logger->info('d-translate: page link anchors pointed at the translated elements', [
                'table' => $table,
                'uid' => (int)$uid,
                'language' => $targetLanguageId,
                'fields' => array_keys($update),
            ]);
        }
        return $changed;
    }

    /**
     * Rewrite every element anchor of a page link in $value to the element's
     * translation in the target language; anchors without one stay as they are.
     */
    public function rewrite(string $value, int $targetLanguageId): string
    {
        $pairs = [];
        $leftAlone = [];
        return $this->rewriteCollecting($value, $targetLanguageId, $pairs, $leftAlone);
    }

    /**
     * rewrite(), collecting each distinct replacement as an [old, new] pair of
     * anchors ("#c12" → "#c34") in $pairs, and each distinct anchor it had to
     * leave alone as [anchor, reason] in $leftAlone — 'untranslated' when the
     * element has no translation in the language, 'missing' when there is no
     * such element. An anchor already naming an element of the language is
     * neither.
     *
     * @param list<array{0: string, 1: string}> $pairs
     * @param list<array{0: string, 1: string}> $leftAlone
     */
    private function rewriteCollecting(string $value, int $targetLanguageId, array &$pairs, array &$leftAlone): string
    {
        if ($targetLanguageId <= 0 || !str_contains($value, 't3://page?uid=')) {
            return $value;
        }
        $result = preg_replace_callback(
            self::PATTERN,
            function (array $match) use ($targetLanguageId, &$pairs, &$leftAlone): string {
                $anchor = '#' . $match[2] . $match[3];
                $translated = $this->translatedElement((int)$match[3], $targetLanguageId);
                if ($translated <= 0) {
                    $reason = $this->reasonLeftAlone((int)$match[3], $targetLanguageId);
                    if ($reason !== '' && !in_array([$anchor, $reason], $leftAlone, true)) {
                        $leftAlone[] = [$anchor, $reason];
                    }
                    return $match[0];
                }
                $pair = [$anchor, '#' . $match[2] . $translated];
                if (!in_array($pair, $pairs, true)) {
                    $pairs[] = $pair;
                }
                return $match[1] . $match[2] . $translated;
            },
            $value
        );
        return is_string($result) ? $result : $value;
    }

    /**
     * Why an anchor stays as it is: '' when it names an element of the target
     * language already (nothing to do), 'missing' when there is no such
     * element, 'untranslated' when the element has no translation there.
     */
    private function reasonLeftAlone(int $uid, int $targetLanguageId): string
    {
        $row = $this->row(self::CONTENT_TABLE, $uid);
        if ($row === null || (int)($row['deleted'] ?? 0) === 1) {
            return 'missing';
        }
        $languageField = (string)($GLOBALS['TCA'][self::CONTENT_TABLE]['ctrl']['languageField'] ?? '');
        return (int)($row[$languageField] ?? 0) === $targetLanguageId ? '' : 'untranslated';
    }

    /**
     * Records of the target language on a page. The page's own translation is
     * found through its translation pointer; everything else through its pid.
     * Records copied into the language (pointer 0, source set) count as well.
     *
     * @return list<int>
     */
    private function translationsOnPage(string $table, int $pageId, int $targetLanguageId): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        if ($languageField === '' || ($table === 'pages' && $parentField === '')) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $constraints = [
            $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
            $table === 'pages'
                ? $qb->expr()->eq($parentField, $qb->createNamedParameter($pageId, ParameterType::INTEGER))
                : $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $rows = $qb->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    /**
     * Records in the target language — on any page — whose link columns point
     * at an anchor on $pageId. A teaser on another page that jumps to a contact
     * box here is translated in its own run; once this page's elements exist in
     * the language, its anchor can be resolved too.
     *
     * @param list<string> $tables
     */
    private function remapLinksToPage(int $pageId, int $targetLanguageId, array $tables, bool $dryRun): int
    {
        $changed = 0;
        foreach ($tables as $table) {
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
            $languageField = (string)($ctrl['languageField'] ?? '');
            $columns = $this->linkBearingColumns($table);
            if ($languageField === '' || $columns === []) {
                continue;
            }
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $likes = [];
            foreach ($columns as $column) {
                // Directly followed by the anchor, or by further query parameters.
                $likes[] = $qb->expr()->like($column, $qb->createNamedParameter('%t3://page?uid=' . $pageId . '#%'));
                $likes[] = $qb->expr()->like($column, $qb->createNamedParameter('%t3://page?uid=' . $pageId . '&%'));
            }
            $constraints = [
                $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
                $qb->expr()->or(...$likes),
            ];
            if (($ctrl['delete'] ?? '') !== '') {
                $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
            }
            $uids = $qb->select('uid')
                ->from($table)
                ->where(...$constraints)
                ->executeQuery()
                ->fetchFirstColumn();
            $changed += $this->remapRecords($table, array_map('intval', $uids), $targetLanguageId, $dryRun);
        }
        return $changed;
    }

    /**
     * Records of the target language on the given pages that hold a page link
     * at all; the regex sorts out which of them carry an element anchor.
     *
     * @param list<int> $pageIds default-language page uids
     * @return list<int>
     */
    private function linkingRecordsOnPages(string $table, array $pageIds, int $targetLanguageId): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $columns = $this->linkBearingColumns($table);
        if ($languageField === '' || $columns === [] || ($table === 'pages' && $parentField === '')) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $likes = [];
        foreach ($columns as $column) {
            $likes[] = $qb->expr()->like($column, $qb->createNamedParameter('%t3://page?uid=%'));
        }
        $constraints = [
            $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
            // The page's own translation hangs off its translation pointer,
            // everything else off its pid.
            $table === 'pages'
                ? $qb->expr()->in($parentField, $qb->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY))
                : $qb->expr()->in('pid', $qb->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)),
            $qb->expr()->or(...$likes),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $uids = $qb->select('uid')
            ->from($table)
            ->where(...$constraints)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $uids);
    }

    /**
     * Localizable tables with at least one column that can hold a typolink.
     *
     * @return list<string>
     */
    private function linkBearingTables(): array
    {
        $tables = [];
        foreach ($GLOBALS['TCA'] ?? [] as $table => $definition) {
            $ctrl = $definition['ctrl'] ?? [];
            if ((string)($ctrl['languageField'] ?? '') === '' || (string)($ctrl['transOrigPointerField'] ?? '') === '') {
                continue;
            }
            if ($this->linkBearingColumns((string)$table) !== []) {
                $tables[] = (string)$table;
            }
        }
        return $tables;
    }

    /**
     * Columns that can hold a typolink: link fields, RTE/text and FlexForm.
     *
     * @return list<string>
     */
    private function linkBearingColumns(string $table): array
    {
        $columns = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column => $definition) {
            $config = $definition['config'] ?? [];
            $type = (string)($config['type'] ?? '');
            if (in_array($type, ['link', 'text', 'flex'], true)
                || ($type === 'input' && (string)($config['renderType'] ?? '') === 'inputLink')
            ) {
                $columns[] = (string)$column;
            }
        }
        return $columns;
    }

    /**
     * The uid of the element's translation in the target language, 0 when the
     * anchor should stay: unknown element, already in that language, or not
     * translated there.
     */
    private function translatedElement(int $uid, int $languageId): int
    {
        $key = $uid . '|' . $languageId;
        if (!isset($this->anchorCache[$key])) {
            $this->anchorCache[$key] = $this->resolveTranslatedElement($uid, $languageId);
        }
        return $this->anchorCache[$key];
    }

    private function resolveTranslatedElement(int $uid, int $languageId): int
    {
        $ctrl = $GLOBALS['TCA'][self::CONTENT_TABLE]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $sourceField = (string)($ctrl['translationSource'] ?? '');
        if ($languageField === '' || $parentField === '') {
            return 0;
        }
        $row = $this->row(self::CONTENT_TABLE, $uid);
        if ($row === null || (int)($row[$languageField] ?? 0) === $languageId) {
            return 0;
        }
        $defaultUid = $this->defaultLanguageElement($row, $languageField, $parentField, $sourceField);
        if ($defaultUid <= 0) {
            return 0;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::CONTENT_TABLE);
        $qb->getRestrictions()->removeAll();
        $pointers = [$qb->expr()->eq($parentField, $qb->createNamedParameter($defaultUid, ParameterType::INTEGER))];
        if ($sourceField !== '') {
            $pointers[] = $qb->expr()->eq($sourceField, $qb->createNamedParameter($defaultUid, ParameterType::INTEGER));
        }
        $constraints = [
            $qb->expr()->eq($languageField, $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            $qb->expr()->or(...$pointers),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $rows = $qb->select('uid', $parentField)
            ->from(self::CONTENT_TABLE)
            ->where(...$constraints)
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        // A connected translation (pointer set) beats a copy into the language.
        foreach ($rows as $candidate) {
            if ((int)($candidate[$parentField] ?? 0) === $defaultUid) {
                return (int)$candidate['uid'];
            }
        }
        return $rows !== [] ? (int)$rows[0]['uid'] : 0;
    }

    /**
     * The default-language element behind a record: the record itself, or the
     * element it was localized or copied from, followed up the chain.
     *
     * @param array<string, mixed> $row
     */
    private function defaultLanguageElement(array $row, string $languageField, string $parentField, string $sourceField): int
    {
        $guard = 0;
        while ((int)($row[$languageField] ?? 0) !== 0) {
            $origin = (int)($row[$parentField] ?? 0);
            if ($origin <= 0 && $sourceField !== '') {
                $origin = (int)($row[$sourceField] ?? 0);
            }
            if ($origin <= 0 || ++$guard > 10) {
                return 0;
            }
            $row = $this->row(self::CONTENT_TABLE, $origin);
            if ($row === null) {
                return 0;
            }
        }
        return (int)($row['uid'] ?? 0);
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $uid): ?array
    {
        if ($table === self::CONTENT_TABLE && array_key_exists($uid, $this->rowCache)) {
            return $this->rowCache[$uid];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        $row = $row !== false ? $row : null;
        if ($table === self::CONTENT_TABLE) {
            $this->rowCache[$uid] = $row;
        }
        return $row;
    }
}
