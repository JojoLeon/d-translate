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
}
