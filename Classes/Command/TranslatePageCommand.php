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

use Dreistein\DTranslate\Service\TranslationOrchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Translates all default-language content of a page into a target language,
 * skipping content already translated. Same orchestrator path as the backend
 * module and (later) the scheduler.
 *
 *   vendor/bin/typo3 dtranslate:page <pageId> <targetLanguageId>
 */
final class TranslatePageCommand extends Command
{
    public function __construct(
        private readonly TranslationOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pageId', InputArgument::REQUIRED, 'page uid whose content is translated')
            ->addArgument('language', InputArgument::REQUIRED, 'target sys_language_uid');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageId = (int)$input->getArgument('pageId');
        $targetLanguageId = (int)$input->getArgument('language');

        Bootstrap::initializeBackendAuthentication();

        $result = $this->orchestrator->translatePage($pageId, $targetLanguageId);
        if (!$result->ok) {
            $io->error($result->message ?: 'Translation failed.');
            return Command::FAILURE;
        }
        if ($result->errors !== []) {
            $io->warning("DataHandler reported:\n - " . implode("\n - ", $result->errors));
        }
        $io->success(sprintf(
            'Page %d → language %d: %d created, %d re-translated. %s',
            $pageId,
            $targetLanguageId,
            $result->created(),
            $result->retranslated,
            $result->message,
        ));
        if ($result->createdUids !== []) {
            $io->writeln('New records: ' . implode(', ', $result->createdUids));
        }

        return Command::SUCCESS;
    }
}
