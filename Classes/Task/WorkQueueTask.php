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

namespace Dreistein\DTranslate\Task;

use Dreistein\DTranslate\Job\QueueWorker;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task for the translation queue — the same work as the console
 * command `dtranslate:work`, for installations that drive background jobs from
 * the scheduler instead of their own cron entry. Schedule it every minute.
 *
 * Hardening contract: this must NEVER let an exception escape. The scheduler
 * treats an uncaught throw as "task failed" and badges it as broken in the
 * backend, while the actual state of each run is already tracked per row in
 * tx_dtranslate_job / tx_dtranslate_job_item. Failures are logged and the next
 * cycle simply continues.
 */
class WorkQueueTask extends AbstractTask implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /** Matches the console command's defaults; one invocation stays short enough to overlap safely. */
    private const DEFAULT_TIME_LIMIT = 240;
    private const DEFAULT_MAX_JOBS = 5;

    public function execute(): bool
    {
        try {
            // Scheduler tasks are unserialized, not built by the container, so
            // the worker has to be fetched (and therefore be a public service).
            $worker = GeneralUtility::makeInstance(ContainerInterface::class)->get(QueueWorker::class);
            $result = $worker->work(
                $this->timeLimit(),
                self::DEFAULT_MAX_JOBS,
                function (string $level, string $message): void {
                    if ($level === QueueWorker::LEVEL_ERROR) {
                        $this->log('dtranslate:work — ' . $message);
                    }
                },
            );
            // The scheduler's execution log only keeps the return value, so a
            // one-line trace of what happened is worth a notice-level entry.
            if ($result['jobs'] > 0) {
                $this->log(sprintf(
                    'dtranslate:work — %d job(s), %d item(s) processed.',
                    $result['jobs'],
                    $result['items'],
                ), 'notice');
            }
        } catch (\Throwable $e) {
            $this->log('dtranslate:work — top-level exception, will retry next cycle', 'critical', $e);
        }
        return true;
    }

    /**
     * The backend's "Run now" button executes in an FPM worker with a real
     * execution limit; staying below it keeps a run from being killed
     * mid-item. CLI reports 0 (unlimited) and keeps the full budget.
     */
    private function timeLimit(): int
    {
        $max = (int)ini_get('max_execution_time');
        if ($max > 0) {
            return max(30, min(self::DEFAULT_TIME_LIMIT, $max - 10));
        }
        return self::DEFAULT_TIME_LIMIT;
    }

    private function log(string $message, string $level = 'warning', ?\Throwable $e = null): void
    {
        try {
            $logger = $this->logger ?? GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
            $logger->log($level, $message, $e ? ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()] : []);
        } catch (\Throwable) {
            // Last resort — error_log goes to PHP's configured error log.
            error_log('[d_translate] ' . $message . ($e ? ' — ' . $e->getMessage() : ''));
        }
    }
}
