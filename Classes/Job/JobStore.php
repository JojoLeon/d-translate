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

namespace Dreistein\DTranslate\Job;

use Doctrine\DBAL\ParameterType;
use Dreistein\DTranslate\Localization\Messages;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Data access for translation jobs and their items.
 *
 * Items are claimed with a locking read so a browser-driven run and the
 * background worker can consume the same queue without translating a record
 * twice — the pattern that also allows several chunks to run in parallel.
 */
final class JobStore
{
    public const TABLE_JOB = 'tx_dtranslate_job';
    public const TABLE_ITEM = 'tx_dtranslate_job_item';

    /** A running job whose worker stopped reporting is up for grabs again. */
    private const STALE_AFTER = 900;

    /** How often one item may be claimed before it is written off as poison. */
    private const MAX_ITEM_ATTEMPTS = 3;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param array<string, list<int>> $selectedByTable
     * @param list<int>                $languageIds
     */
    public function create(
        int $pageId,
        array $languageIds,
        array $selectedByTable,
        bool $publishPage,
        bool $background,
        bool $skipTranslated = false,
        int $beUser = 0,
        int $profileUid = 0,
    ): TranslationJob {
        $now = time();
        $jobConnection = $this->connectionPool->getConnectionForTable(self::TABLE_JOB);
        $jobConnection->insert(self::TABLE_JOB, [
            'crdate' => $now,
            'tstamp' => $now,
            'page_id' => $pageId,
            'profile' => $profileUid,
            'languages' => implode(',', $languageIds),
            'publish_page' => (int)$publishPage,
            'skip_translated' => (int)$skipTranslated,
            'background' => (int)$background,
            'state' => $background ? TranslationJob::STATE_QUEUED : TranslationJob::STATE_RUNNING,
            'started' => $background ? 0 : $now,
            'heartbeat' => $now,
            'be_user' => $beUser,
        ]);
        $jobId = (int)$jobConnection->lastInsertId();

        // One item per record and language, page metadata first for each
        // language — the page translation has to exist before its records.
        $itemConnection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        $sorting = 0;
        foreach ($languageIds as $languageId) {
            $itemConnection->insert(self::TABLE_ITEM, [
                'job' => $jobId,
                'sorting' => $sorting++,
                'table_name' => '',
                'record_uid' => 0,
                'language_id' => $languageId,
                'state' => 'pending',
                'tstamp' => $now,
            ]);
            foreach ($selectedByTable as $table => $uids) {
                foreach ($uids as $uid) {
                    $itemConnection->insert(self::TABLE_ITEM, [
                        'job' => $jobId,
                        'sorting' => $sorting++,
                        'table_name' => $table,
                        'record_uid' => (int)$uid,
                        'language_id' => $languageId,
                        'state' => 'pending',
                        'tstamp' => $now,
                    ]);
                }
            }
        }
        $jobConnection->update(self::TABLE_JOB, ['total' => $sorting], ['uid' => $jobId]);

        return $this->find($jobId) ?? new TranslationJob(['uid' => $jobId]);
    }

    public function find(int $jobId): ?TranslationJob
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE_JOB)
            ->select(['*'], self::TABLE_JOB, ['uid' => $jobId])
            ->fetchAssociative();
        return is_array($row) ? new TranslationJob($row) : null;
    }

    /**
     * Claim the next pending item of a job. Returns null when the job has no
     * work left. The UPDATE-then-read keeps two workers off the same item.
     */
    public function claimNextItem(int $jobId): ?JobItem
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE_ITEM);
        $row = $connection->select(
            ['*'],
            self::TABLE_ITEM,
            ['job' => $jobId, 'state' => 'pending'],
            [],
            ['sorting' => 'ASC'],
            1
        )->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        // A record that kills the worker mid-item leaves its item claimed; the
        // stale-job recovery hands it back to "pending" and it is tried again.
        // Without a ceiling that is a loop: the same record takes the whole
        // queue down every 15 minutes and the run never ends.
        if ((int)$row['attempts'] >= self::MAX_ITEM_ATTEMPTS) {
            $this->completeItem(new JobItem($row), false, Messages::tr('run.itemGaveUp', self::MAX_ITEM_ATTEMPTS));
            return $this->claimNextItem($jobId);
        }
        $claimed = $connection->update(
            self::TABLE_ITEM,
            ['state' => 'running', 'tstamp' => time(), 'attempts' => (int)$row['attempts'] + 1],
            ['uid' => (int)$row['uid'], 'state' => 'pending'],
        );
        if ($claimed === 0) {
            // Someone else was faster — try the next one.
            return $this->claimNextItem($jobId);
        }
        return new JobItem($row);
    }

    public function completeItem(JobItem $item, bool $ok, string $message = ''): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['state' => $ok ? 'done' : 'failed', 'message' => $message, 'tstamp' => time()],
            ['uid' => $item->uid()],
        );
        $field = $ok ? 'done' : 'failed';
        $this->connectionPool->getConnectionForTable(self::TABLE_JOB)->executeStatement(
            sprintf(
                'UPDATE %s SET %s = %s + 1, heartbeat = :now, tstamp = :now WHERE uid = :job',
                self::TABLE_JOB,
                $field,
                $field,
            ),
            ['now' => time(), 'job' => $item->jobId()],
        );
    }

    /** Put a claimed item back into the queue (worker ran out of time). */
    public function releaseItem(JobItem $item): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['state' => 'pending', 'tstamp' => time()],
            ['uid' => $item->uid(), 'state' => 'running'],
        );
    }

    public function addUsage(int $jobId, int $tokensIn, int $tokensOut, int $calls): void
    {
        if ($jobId <= 0 || ($tokensIn === 0 && $tokensOut === 0 && $calls === 0)) {
            return;
        }
        $this->connectionPool->getConnectionForTable(self::TABLE_JOB)->executeStatement(
            sprintf(
                'UPDATE %s SET tokens_in = tokens_in + :in, tokens_out = tokens_out + :out,'
                    . ' calls = calls + :calls, heartbeat = :now WHERE uid = :job',
                self::TABLE_JOB,
            ),
            ['in' => $tokensIn, 'out' => $tokensOut, 'calls' => $calls, 'now' => time(), 'job' => $jobId],
        );
    }

    public function setState(int $jobId, string $state, string $message = ''): void
    {
        $data = ['state' => $state, 'tstamp' => time(), 'heartbeat' => time()];
        if ($message !== '') {
            $data['message'] = $message;
        }
        if ($state === TranslationJob::STATE_RUNNING) {
            $data['started'] = time();
        }
        if (in_array($state, [TranslationJob::STATE_DONE, TranslationJob::STATE_FAILED, TranslationJob::STATE_CANCELLED], true)) {
            $data['finished'] = time();
        }
        $this->connectionPool->getConnectionForTable(self::TABLE_JOB)->update(self::TABLE_JOB, $data, ['uid' => $jobId]);
    }

    public function heartbeat(int $jobId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_JOB)
            ->update(self::TABLE_JOB, ['heartbeat' => time()], ['uid' => $jobId]);
    }

    /** Close a job once no item is left, keeping "failed" visible. */
    public function finishIfComplete(int $jobId): ?TranslationJob
    {
        $open = $this->countItems($jobId, ['pending', 'running']);
        if ($open > 0) {
            return $this->find($jobId);
        }
        $job = $this->find($jobId);
        if ($job === null || !$job->isOpen()) {
            return $job;
        }
        $this->setState($jobId, TranslationJob::STATE_DONE);
        return $this->find($jobId);
    }

    public function cancel(int $jobId): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['state' => 'cancelled', 'tstamp' => time()],
            ['job' => $jobId, 'state' => 'pending'],
        );
        $this->setState($jobId, TranslationJob::STATE_CANCELLED);
    }

    /** @param list<string> $states */
    public function countItems(int $jobId, array $states): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_ITEM);
        return (int)$qb->count('uid')
            ->from(self::TABLE_ITEM)
            ->where(
                $qb->expr()->eq('job', $qb->createNamedParameter($jobId, ParameterType::INTEGER)),
                $qb->expr()->in('state', $qb->createNamedParameter($states, Connection::PARAM_STR_ARRAY)),
            )
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * A job that already covers one of these languages for the page and is not
     * finished — the guard against two editors paying for the same run.
     *
     * @param list<int> $languageIds
     */
    public function findOpenForPage(int $pageId, array $languageIds): ?TranslationJob
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_JOB);
        $rows = $qb->select('*')
            ->from(self::TABLE_JOB)
            ->where(
                $qb->expr()->eq('page_id', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
                $qb->expr()->in('state', $qb->createNamedParameter(
                    [TranslationJob::STATE_QUEUED, TranslationJob::STATE_RUNNING],
                    Connection::PARAM_STR_ARRAY
                )),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $job = new TranslationJob($row);
            if (array_intersect($job->languages(), $languageIds) !== []) {
                return $job;
            }
        }
        return null;
    }

    /** Oldest queued job, or a running one whose worker went silent. @return TranslationJob|null */
    public function claimNextJob(): ?TranslationJob
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_JOB);
        $row = $qb->select('*')
            ->from(self::TABLE_JOB)
            ->where(
                $qb->expr()->or(
                    $qb->expr()->eq('state', $qb->createNamedParameter(TranslationJob::STATE_QUEUED)),
                    $qb->expr()->and(
                        $qb->expr()->eq('state', $qb->createNamedParameter(TranslationJob::STATE_RUNNING)),
                        $qb->expr()->lt('heartbeat', $qb->createNamedParameter(time() - self::STALE_AFTER, ParameterType::INTEGER)),
                    ),
                ),
            )
            ->orderBy('crdate', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        $this->setState((int)$row['uid'], TranslationJob::STATE_RUNNING);
        // A stale running job may still hold claimed items; hand them back.
        $this->connectionPool->getConnectionForTable(self::TABLE_ITEM)->update(
            self::TABLE_ITEM,
            ['state' => 'pending'],
            ['job' => (int)$row['uid'], 'state' => 'running'],
        );
        return $this->find((int)$row['uid']);
    }

    /** @return list<TranslationJob> */
    public function listRecent(int $limit = 10): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE_JOB);
        $rows = $qb->select('*')
            ->from(self::TABLE_JOB)
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(static fn (array $row): TranslationJob => new TranslationJob($row), $rows);
    }
}
