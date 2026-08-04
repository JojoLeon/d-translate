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

use Doctrine\DBAL\Exception as DbalException;
use Dreistein\DTranslate\Configuration\ExtensionSettings;
use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Service\TranslationOrchestrator;
use Dreistein\DTranslate\Service\TranslationRunResult;
use Psr\Log\LoggerInterface;

/**
 * Executes one job item and reports it back in editor language. The single
 * place where a unit of work is turned into "what happened" — used by the
 * browser-driven run and by the background worker alike.
 */
final class JobRunner
{
    public function __construct(
        private readonly TranslationOrchestrator $orchestrator,
        private readonly JobStore $jobStore,
        private readonly RunUsage $usage,
        private readonly LoggerInterface $logger,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * @return array{ok: bool, fatal: bool, message: string}
     */
    public function process(TranslationJob $job, JobItem $item): array
    {
        $budget = $this->settings->maxCallsPerJob();
        if ($budget > 0 && $job->calls() >= $budget) {
            // Treated as fatal so the queue stops instead of draining the budget
            // item by item; the editor decides whether to raise the cap.
            return ['ok' => false, 'fatal' => true, 'message' => Messages::tr('run.budgetExceeded', $budget)];
        }

        $selection = $item->isPageMeta() ? [] : [$item->table() => [$item->recordUid()]];
        $before = [$this->usage->tokensIn(), $this->usage->tokensOut(), $this->usage->calls()];

        try {
            $result = $this->orchestrator->translateSelection(
                $job->pageId(),
                $item->languageId(),
                $selection,
                $job->publishPage(),
                $item->isPageMeta(),
                $job->profileUid(),
                $job->skipTranslated(),
            );
        } catch (\Throwable $e) {
            $this->logger->error('d-translate: job item failed', [
                'job' => $job->uid(),
                'item' => $item->uid(),
                'table' => $item->table(),
                'uid' => $item->recordUid(),
                'language' => $item->languageId(),
                'exception' => $e->getMessage(),
            ]);
            $this->flushUsage($job, $before);
            return [
                'ok' => false,
                // A rejected query is about this one record (a value the column
                // will not take); the next record may be perfectly fine. Only
                // provider-level failures — wrong key, unknown model, quota —
                // make every remaining item pointless.
                'fatal' => $e instanceof DbalException
                    ? false
                    : (!$e instanceof LlmRequestException || $e->fatal),
                'message' => $e instanceof LlmRequestException
                    ? $e->userMessage
                    : Messages::tr('run.genericError', (new \ReflectionClass($e))->getShortName(), $e->getMessage()),
            ];
        }

        $this->flushUsage($job, $before);

        if (!$result->ok) {
            $detail = $result->errors !== []
                ? implode('; ', array_slice($result->errors, 0, 3))
                : ($result->message ?: Messages::tr('run.failed'));
            return ['ok' => false, 'fatal' => false, 'message' => $detail];
        }

        $message = $this->describe($result);
        if ($result->errors !== []) {
            $message .= ' (' . Messages::tr('run.hints', implode('; ', array_slice($result->errors, 0, 2))) . ')';
        }
        return ['ok' => true, 'fatal' => false, 'message' => $message];
    }

    /**
     * What a successful unit of work did, page translation included — the page
     * unit carries no records and could otherwise only ever say "no change".
     */
    public function describe(TranslationRunResult $result): string
    {
        $parts = [];
        $page = match ($result->pageStatus) {
            TranslationOrchestrator::PAGE_CREATED => Messages::tr('run.pageCreated'),
            TranslationOrchestrator::PAGE_UPDATED => Messages::tr('run.pageUpdated'),
            default => '',
        };
        if ($page !== '') {
            $parts[] = $page;
        }
        if ($result->created() > 0 && $result->retranslated > 0) {
            $parts[] = Messages::tr('run.createdAndUpdated', $result->created(), $result->retranslated);
        } elseif ($result->created() > 0) {
            $parts[] = Messages::tr('run.created', $result->created());
        } elseif ($result->retranslated > 0) {
            $parts[] = Messages::tr('run.updated', $result->retranslated);
        }
        if ($parts === []) {
            $aboutPage = $result->requested === 0 && $result->retranslated === 0;
            $parts[] = $aboutPage && $result->pageStatus === TranslationOrchestrator::PAGE_UNCHANGED
                ? Messages::tr('run.pageUnchanged')
                : Messages::tr('run.noChange');
        }
        return implode(', ', $parts);
    }

    /** @param array{0: int, 1: int, 2: int} $before */
    private function flushUsage(TranslationJob $job, array $before): void
    {
        $this->jobStore->addUsage(
            $job->uid(),
            $this->usage->tokensIn() - $before[0],
            $this->usage->tokensOut() - $before[1],
            $this->usage->calls() - $before[2],
        );
    }
}
