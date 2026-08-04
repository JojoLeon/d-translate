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

namespace Dreistein\DTranslate\Command;

use Dreistein\DTranslate\Job\QueueWorker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Worker for queued translation runs — the background half of the module.
 *
 * Run it from cron (every minute is fine); the time limit keeps one invocation
 * short enough to overlap safely with the next. The same work is available as
 * the scheduler task "dTRANSLATE: Process translation queue".
 */
final class WorkQueueCommand extends Command
{
    public function __construct(
        private readonly QueueWorker $worker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Processes queued translation jobs created by the backend module.')
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Seconds to keep working', '240')
            ->addOption('jobs', null, InputOption::VALUE_REQUIRED, 'Maximum number of jobs per run', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $result = $this->worker->work(
            (int)$input->getOption('time-limit'),
            (int)$input->getOption('jobs'),
            static function (string $level, string $message) use ($io): void {
                match ($level) {
                    QueueWorker::LEVEL_JOB => $io->writeln('<info>' . $message . '</info>'),
                    QueueWorker::LEVEL_ERROR => $io->error($message),
                    QueueWorker::LEVEL_NOTE => $io->note($message),
                    default => $io->writeln('  ' . $message),
                };
            },
        );

        // Quiet when there was nothing to do: this runs every minute from cron
        // and an idle tick should not fill the log.
        if ($result['jobs'] > 0) {
            $io->success(sprintf('%d job(s), %d item(s) processed.', $result['jobs'], $result['items']));
        }
        return Command::SUCCESS;
    }
}
