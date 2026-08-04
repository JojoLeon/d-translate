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
 * Thin data-access for tx_dtranslate_profile (Default connection). Profiles are
 * keyed by TYPO3 site identifier.
 */
final class ProfileStore
{
    private const TABLE = 'tx_dtranslate_profile';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return $qb->select('uid', 'title', 'site_identifier', 'llm_config', 'formality', 'translate_tables', 'hidden')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('deleted', 0))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
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

    /** Selectable profiles for the module, enabled ones only. @return list<array<string, mixed>> */
    public function listSelectable(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        return $qb->select('uid', 'title', 'site_identifier', 'formality')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->orderBy('title')
            ->addOrderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * The profile marked as default: no site of its own, so it applies wherever
     * no site-specific profile exists.
     *
     * @return array<string, mixed>|null
     */
    public function findDefault(): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter('')),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** First non-deleted, enabled profile — fallback for records outside a site rootline. @return array<string, mixed>|null */
    public function first(): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function findBySiteIdentifier(string $siteIdentifier): ?array
    {
        if ($siteIdentifier === '') {
            return null;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
            )
            // Deterministic when someone configured two profiles for one site.
            ->orderBy('uid')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
