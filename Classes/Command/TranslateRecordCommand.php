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

use Dreistein\DTranslate\Service\ProfileLlmResolver;
use Dreistein\DTranslate\Service\TranslationOrchestrator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * CLI translation trigger. Delegates the actual work to the
 * {@see TranslationOrchestrator} (the same path the backend module uses) and
 * reports the before/after fields. Kept as a scriptable entry point alongside
 * the backend module.
 *
 *   vendor/bin/typo3 dtranslate:translate-record <uid> <targetLanguageId> [table]
 */
final class TranslateRecordCommand extends Command
{
    public function __construct(
        private readonly TranslationOrchestrator $orchestrator,
        private readonly ProfileLlmResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Localizes a record and translates its fields through the real DataHandler hook path.')
            ->addArgument('uid', InputArgument::REQUIRED, 'uid of the default-language source record')
            ->addArgument('language', InputArgument::REQUIRED, 'target sys_language_uid to translate into')
            ->addArgument('table', InputArgument::OPTIONAL, 'table name', 'tt_content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)$input->getArgument('uid');
        $targetLanguageId = (int)$input->getArgument('language');
        $table = (string)$input->getArgument('table');

        Bootstrap::initializeBackendAuthentication();

        $source = BackendUtility::getRecord($table, $uid);
        if (!is_array($source)) {
            $io->error(sprintf('Record %s:%d not found.', $table, $uid));
            return Command::FAILURE;
        }

        $pid = (int)($source['pid'] ?? 0);
        $profile = $this->resolver->resolveForPage($pid);
        $io->section('d-translate');
        $io->definitionList(
            ['record' => sprintf('%s:%d (pid %d)', $table, $uid, $pid)],
            ['direction' => sprintf('%s → %s (sys_language_uid %d)', $profile->sourceLanguage, $this->orchestrator->resolveTargetLanguage($pid, $targetLanguageId), $targetLanguageId)],
            ['llm' => sprintf('%s · %s', $profile->llm->label, $profile->llm->chatModel)],
            ['formality' => $profile->formality],
        );

        $result = $this->orchestrator->translateRecord($table, $uid, $targetLanguageId);
        if (!$result->ok) {
            $io->error($result->message ?: 'Translation failed.');
            return Command::FAILURE;
        }
        if ($result->errors !== []) {
            $io->warning("DataHandler reported:\n - " . implode("\n - ", $result->errors));
        }
        if ($result->created() === 0) {
            $io->warning($result->message ?: 'No new record produced (already translated?).');
            return Command::FAILURE;
        }

        $newUid = $result->createdUids[0];
        $translated = BackendUtility::getRecord($table, $newUid) ?? [];
        $io->success(sprintf('Localized into %s:%d', $table, $newUid));
        $this->reportFields($io, $source, $translated);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $translated
     */
    private function reportFields(SymfonyStyle $io, array $source, array $translated): void
    {
        $interesting = ['header', 'subheader', 'bodytext', 'title'];
        $rows = [];
        foreach ($interesting as $field) {
            if (!array_key_exists($field, $source) && !array_key_exists($field, $translated)) {
                continue;
            }
            $rows[] = [$field, $this->shorten((string)($source[$field] ?? '')), $this->shorten((string)($translated[$field] ?? ''))];
        }
        if ($rows !== []) {
            $io->table(['field', 'source', 'translated'], $rows);
        }
    }

    private function shorten(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        return mb_strlen($value) > 80 ? mb_substr($value, 0, 77) . '…' : $value;
    }
}
