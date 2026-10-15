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
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Data access for tx_dtranslate_glossary.
 *
 * An entry belongs to a profile — or to every profile (profile 0) — and names
 * what it applies to in `target_language`: '-1' for every target language, a
 * language ('en'), a regional variant ('en-GB') or a single site language id
 * ('15'); see {@see LanguageCatalog}.
 */
final class GlossaryStore
{
    public const SHARED_PROFILE = 0;

    private const TABLE = 'tx_dtranslate_glossary';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * The term pairs that apply to a target language: the profile's own and
     * the shared ones, filtered by the keys the target answers to. When several
     * entries name the same source term, the most specific one wins (site
     * language id over regional variant over language over "every language"),
     * and the profile's own entry over a shared one.
     *
     * @param list<string> $targetKeys keys the target language answers to, see LanguageCatalog::keysFor()
     * @return list<array{source_term: string, target_term: string, note: string}>
     */
    public function findForProfile(int $profileUid, array $targetKeys): array
    {
        $targetKeys = array_values(array_unique(array_map('strval', $targetKeys)));
        if ($targetKeys === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('profile', 'target_language', 'source_term', 'target_term', 'note')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->in('profile', $qb->createNamedParameter(array_unique([self::SHARED_PROFILE, max(0, $profileUid)]), Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
                $qb->expr()->in('target_language', $qb->createNamedParameter($targetKeys, Connection::PARAM_STR_ARRAY)),
            )
            ->orderBy('sorting')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();

        // Rank: language specificity first, the profile's own entry before a shared one.
        $rank = static fn (array $row): int => LanguageCatalog::specificity((string)$row['target_language']) * 2
            + ((int)$row['profile'] > 0 ? 1 : 0);
        $best = [];
        foreach ($rows as $row) {
            $term = mb_strtolower(trim((string)$row['source_term']));
            $best[$term] = max($best[$term] ?? -1, $rank($row));
        }
        $result = [];
        foreach ($rows as $row) {
            $term = mb_strtolower(trim((string)$row['source_term']));
            if ($rank($row) !== $best[$term]) {
                continue;
            }
            $result[] = [
                'source_term' => (string)$row['source_term'],
                'target_term' => (string)$row['target_term'],
                'note' => (string)$row['note'],
            ];
        }
        return $result;
    }

    /**
     * One page of a profile's entries for the module's glossary list, hidden
     * ones included. $languageKey null = every entry, otherwise the entries
     * stored with exactly that key ('-1', 'en', 'en-GB', '15').
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $profileUid, ?string $languageKey, string $search, int $offset, int $limit): array
    {
        return $this->listQuery($profileUid, $languageKey, $search)
            ->select('uid', 'source_term', 'target_term', 'target_language', 'note', 'hidden')
            ->orderBy('source_term')
            ->addOrderBy('target_language')
            ->addOrderBy('uid')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function count(int $profileUid, ?string $languageKey = null, string $search = ''): int
    {
        if ($profileUid < 0) {
            return 0;
        }
        return (int)$this->listQuery($profileUid, $languageKey, $search)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Entries per stored key of a profile ('-1' = every target language).
     *
     * @return array<string, int>
     */
    public function countByLanguage(int $profileUid): array
    {
        if ($profileUid < 0) {
            return [];
        }
        $rows = $this->listQuery($profileUid, null, '')
            ->select('target_language')
            ->addSelectLiteral('COUNT(*) AS entries')
            ->groupBy('target_language')
            ->executeQuery()
            ->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row['target_language']] = (int)$row['entries'];
        }
        return $counts;
    }

    /**
     * Every live entry of the given profiles, for the consolidation command.
     *
     * @param list<int> $profileUids
     * @return list<array<string, mixed>>
     */
    public function allForProfiles(array $profileUids): array
    {
        if ($profileUids === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return $qb->select('uid', 'profile', 'target_language', 'source_term', 'target_term', 'note', 'hidden')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->in('profile', $qb->createNamedParameter($profileUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('profile')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /** @param array<string, int|string> $values */
    public function update(int $uid, array $values): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, $values + ['tstamp' => time()], ['uid' => $uid]);
    }

    /** Soft delete: the entry lands in the recycler and can be restored. @param list<int> $uids */
    public function softDelete(array $uids): void
    {
        if ($uids === []) {
            return;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->update(self::TABLE)
            ->set('deleted', 1)
            ->set('tstamp', time())
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeStatement();
    }

    private function listQuery(int $profileUid, ?string $languageKey, string $search): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->from(self::TABLE)
            ->where(
                $qb->expr()->eq('profile', $qb->createNamedParameter($profileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            );
        if ($languageKey !== null) {
            $qb->andWhere($qb->expr()->eq('target_language', $qb->createNamedParameter(trim($languageKey))));
        }
        $search = trim($search);
        if ($search !== '') {
            $like = $qb->createNamedParameter('%' . $qb->escapeLikeWildcards($search) . '%');
            $qb->andWhere($qb->expr()->or(
                $qb->expr()->like('source_term', $like),
                $qb->expr()->like('target_term', $like),
                $qb->expr()->like('note', $like),
            ));
        }
        return $qb;
    }
}
