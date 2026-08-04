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

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Gives a translated record the same disable state as the record it was
 * translated from.
 *
 * Doing nothing is not an option: tt_content and pages carry the TCA ctrl flag
 * "hideAtCopy", so DataHandler hides every freshly localized record no matter
 * what its source looked like. d-translate used to answer that by forcing all
 * translations visible — which published elements the editor had deliberately
 * taken offline. Mirroring the source is what an editor expects: a hidden
 * element stays hidden, a visible one stays visible, and the decision is made
 * once, on the source record.
 *
 * Records whose source cannot be resolved are left untouched. Guessing is
 * worse than leaving an editor's own setting alone.
 */
final class SourceVisibility
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function mirror(string $table, int $uid): void
    {
        $this->mirrorAll($table, [$uid]);
    }

    /**
     * @param list<int> $translationUids
     */
    public function mirrorAll(string $table, array $translationUids): void
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $disabledField = (string)($ctrl['enablecolumns']['disabled'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        $uids = array_values(array_filter(
            array_map('intval', $translationUids),
            static fn (int $uid): bool => $uid > 0,
        ));
        if ($disabledField === '' || $parentField === '' || $uids === []) {
            return;
        }

        $sourceOf = $this->sourceUids($table, $uids, $parentField);
        if ($sourceOf === []) {
            return;
        }

        $disabledOf = $this->disabledStates($table, array_values(array_unique($sourceOf)), $disabledField);
        $connection = $this->connectionPool->getConnectionForTable($table);
        foreach ($sourceOf as $translationUid => $sourceUid) {
            if (!array_key_exists($sourceUid, $disabledOf)) {
                continue;
            }
            $connection->update($table, [$disabledField => $disabledOf[$sourceUid]], ['uid' => $translationUid]);
        }
    }

    /**
     * @param list<int> $uids
     * @return array<int, int> translation uid => source uid
     */
    private function sourceUids(string $table, array $uids, string $parentField): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', $parentField)
            ->from($table)
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $source = (int)($row[$parentField] ?? 0);
            if ($source > 0) {
                $map[(int)$row['uid']] = $source;
            }
        }
        return $map;
    }

    /**
     * @param list<int> $uids
     * @return array<int, int> uid => disable flag
     */
    private function disabledStates(string $table, array $uids, string $disabledField): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', $disabledField)
            ->from($table)
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['uid']] = (int)($row[$disabledField] ?? 0);
        }
        return $map;
    }
}
