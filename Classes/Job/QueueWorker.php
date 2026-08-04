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

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Works off the queue: claims jobs, runs their items, keeps the state honest.
 *
 * Shared by the console command and the scheduler task so both entry points
 * behave identically — the only difference is where the progress lines go.
 * Items are claimed one at a time, so an interrupted worker loses at most the
 * item it was working on and the run continues where it stopped.
 */
final class QueueWorker
{
    /** Progress levels handed to the report callback. */
    public const LEVEL_JOB = 'job';
    public const LEVEL_ITEM = 'item';
    public const LEVEL_ERROR = 'error';
    public const LEVEL_NOTE = 'note';

    public function __construct(
        private readonly JobStore $jobStore,
        private readonly JobRunner $jobRunner,
    ) {
    }

    /**
     * @param callable(string, string): void|null $report Called with (level, message)
     * @return array{jobs: int, items: int, error: string|null}
     */
    public function work(int $timeLimit, int $maxJobs, ?callable $report = null): array
    {
        $deadline = time() + max(30, $timeLimit);
        $maxJobs = max(1, $maxJobs);
        $tell = static function (string $level, string $message) use ($report): void {
            if ($report !== null) {
                $report($level, $message);
            }
        };

        // DataHandler needs an AUTHENTICATED backend user. The typo3 CLI
        // pre-creates a CommandLineUserAuthentication "not logged in yet", so
        // an instanceof check alone is a trap: the object exists, isAdmin() is
        // false, and every DataHandler command fails with "without permission".
        // Check for an actual login instead; in the backend (scheduler "run
        // now") a real user is logged in and must not be touched.
        $beUser = $GLOBALS['BE_USER'] ?? null;
        if (!$beUser instanceof BackendUserAuthentication || empty($beUser->user['uid'])) {
            Bootstrap::initializeBackendAuthentication();
        }

        $jobs = 0;
        $items = 0;
        $error = null;

        while ($jobs < $maxJobs && time() < $deadline) {
            $job = $this->jobStore->claimNextJob();
            if ($job === null) {
                break;
            }
            $jobs++;
            $tell(self::LEVEL_JOB, sprintf('Job %d (page %d, %d items)', $job->uid(), $job->pageId(), $job->total()));

            while (time() < $deadline) {
                $item = $this->jobStore->claimNextItem($job->uid());
                if ($item === null) {
                    break;
                }
                $outcome = $this->jobRunner->process($job, $item);
                $this->jobStore->completeItem($item, $outcome['ok'], $outcome['message']);
                $items++;
                $tell(self::LEVEL_ITEM, sprintf(
                    '%s %s [lang %d] %s',
                    $outcome['ok'] ? '✓' : '✗',
                    $item->isPageMeta() ? 'page metadata' : $item->label(),
                    $item->languageId(),
                    $outcome['message'],
                ));
                if ($outcome['fatal']) {
                    // A wrong key or model fails the same way for every
                    // remaining item — stop instead of burning the queue.
                    $this->jobStore->setState($job->uid(), TranslationJob::STATE_FAILED, $outcome['message']);
                    $error = $outcome['message'];
                    $tell(self::LEVEL_ERROR, $outcome['message']);
                    break 2;
                }
            }

            $finished = $this->jobStore->finishIfComplete($job->uid());
            if ($finished !== null && $finished->isOpen()) {
                // Out of time — hand the job back to the queue so the next
                // invocation can pick it straight up. Leaving it "running" and
                // only touching the heartbeat looked equivalent but was not:
                // claimNextJob() takes a running job only once its heartbeat is
                // 15 minutes stale, so a paused job sat idle for that long
                // between bursts although a worker started every minute.
                $this->jobStore->setState($job->uid(), TranslationJob::STATE_QUEUED);
                $tell(self::LEVEL_NOTE, sprintf(
                    'Job %d paused at %d/%d',
                    $job->uid(),
                    $finished->done() + $finished->failed(),
                    $finished->total(),
                ));
            }
        }

        return ['jobs' => $jobs, 'items' => $items, 'error' => $error];
    }
}
