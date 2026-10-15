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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Data-access for tx_dtranslate_glossary. Returns the term pairs for a profile
 * that apply to a given target language (or to all languages, target = -1).
 */
final class GlossaryStore
{
    private const TABLE = 'tx_dtranslate_glossary';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /** @return list<array{source_term: string, target_term: string, note: string}> */
    public function findForProfile(int $profileUid, int $targetLanguageId): array
    {
        if ($profileUid <= 0) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('source_term', 'target_term', 'note')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('profile', $qb->createNamedParameter($profileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
                $qb->expr()->or(
                    $qb->expr()->eq('target_language', $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
                    $qb->expr()->eq('target_language', $qb->createNamedParameter(-1, ParameterType::INTEGER)),
                ),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $r): array => [
                'source_term' => (string)$r['source_term'],
                'target_term' => (string)$r['target_term'],
                'note' => (string)$r['note'],
            ],
            $rows,
        );
    }

    /**
     * One page of a profile's entries for the module's glossary list, hidden
     * ones included. $languageId null = every entry, -1 = the entries that
     * apply to all target languages, otherwise the entries of that language.
     *
     * @return list<array<string, mixed>>
     */
    public function page(int $profileUid, ?int $languageId, string $search, int $offset, int $limit): array
    {
        return $this->listQuery($profileUid, $languageId, $search)
            ->select('uid', 'source_term', 'target_term', 'target_language', 'note', 'hidden')
            ->orderBy('source_term')
            ->addOrderBy('target_language')
            ->addOrderBy('uid')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function count(int $profileUid, ?int $languageId = null, string $search = ''): int
    {
        if ($profileUid <= 0) {
            return 0;
        }
        return (int)$this->listQuery($profileUid, $languageId, $search)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Entries per target language of a profile (-1 = all target languages).
     *
     * @return array<int, int>
     */
    public function countByLanguage(int $profileUid): array
    {
        if ($profileUid <= 0) {
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
            $counts[(int)$row['target_language']] = (int)$row['entries'];
        }
        return $counts;
    }

    private function listQuery(int $profileUid, ?int $languageId, string $search): QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $qb->from(self::TABLE)
            ->where(
                $qb->expr()->eq('profile', $qb->createNamedParameter($profileUid, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            );
        if ($languageId !== null) {
            $qb->andWhere($qb->expr()->eq('target_language', $qb->createNamedParameter($languageId, ParameterType::INTEGER)));
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
