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

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Thin data-access for tx_dtranslate_llm_config (Default connection). Returns
 * plain arrays; no Extbase. Mirrors the d-ai-pro LlmConfigStore pattern.
 */
final class LlmConfigStore
{
    private const TABLE = 'tx_dtranslate_llm_config';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'title', 'type', 'chat_model', 'endpoint', 'hidden')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('deleted', 0))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
