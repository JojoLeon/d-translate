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

use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Llm\ResolvedProfile;
use Dreistein\DTranslate\Service\ColumnTranslator;
use Dreistein\DTranslate\Service\GlossaryProvider;
use Dreistein\DTranslate\Service\LanguageNameResolver;
use Dreistein\DTranslate\Service\ProfileLlmResolver;
use Dreistein\DTranslate\Translation\ProcessingContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Translates the columns of existing translations that still hold the source
 * text. A column added to a profile's "additional fields" only reaches
 * translations made from then on; the ones made before keep the copy of the
 * source wording — product subtitles in German on every English page. This is
 * the catch-up for them: only columns identical to the source are sent to the
 * model, so reviewed translations are never overwritten.
 */
final class FillColumnsCommand extends Command
{
    /** l10n_state values that tie a column to the source record's value. */
    private const SYNCHRONIZED_STATES = ['parent', 'source'];

    /** @var array<int, ResolvedProfile> */
    private array $profiles = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ProfileLlmResolver $resolver,
        private readonly ColumnTranslator $columnTranslator,
        private readonly GlossaryProvider $glossaryProvider,
        private readonly LanguageNameResolver $languageNames,
        private readonly ProcessingContext $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                "Translates the columns of existing translations of <table> that are still identical to\n"
                . "the source record. Default columns: the profile's additional fields for the table (the\n"
                . "profile is resolved per record pid, as in a regular run); --column names them instead.\n"
                . "Columns synchronized with the source (l10n_state \"parent\"/\"source\") are left alone.\n"
                . "Changed records get a new tstamp, so publishing workflows see them.\n"
                . "--dry-run lists the records and columns that would be translated and writes nothing."
            )
            ->addArgument('table', InputArgument::REQUIRED, 'table name, e.g. tx_news_domain_model_news')
            ->addOption('column', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'column to fill; repeatable (default: the profile\'s additional fields for the table)')
            ->addOption('language', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'target sys_language_uid; repeatable (default: every language)')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'only records on this page/folder; repeatable')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'list what would be translated, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $table = (string)$input->getArgument('table');
        $dryRun = (bool)$input->getOption('dry-run');
        $requestedColumns = array_values(array_filter(array_map('trim', (array)$input->getOption('column'))));

        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $pointerField = (string)($ctrl['transOrigPointerField'] ?? '');
        if ($languageField === '' || $pointerField === '') {
            $io->error(sprintf('Table "%s" is not localizable.', $table));
            return Command::FAILURE;
        }
        foreach ($requestedColumns as $column) {
            if (!isset($GLOBALS['TCA'][$table]['columns'][$column])) {
                $io->error(sprintf('Column "%s.%s" has no TCA.', $table, $column));
                return Command::FAILURE;
            }
        }

        Bootstrap::initializeBackendAuthentication();
        $this->context->clearErrors();

        $translations = $this->translations(
            $table,
            $languageField,
            $pointerField,
            array_map('intval', (array)$input->getOption('language')),
            array_map('intval', (array)$input->getOption('pid')),
        );
        $sources = $this->sources($table, array_unique(array_map(static fn (array $row): int => (int)$row[$pointerField], $translations)));

        $plan = [];
        foreach ($translations as $translation) {
            $source = $sources[(int)$translation[$pointerField]] ?? null;
            if ($source === null) {
                continue;
            }
            $pid = (int)$translation['pid'];
            $columns = $requestedColumns !== [] ? $requestedColumns : $this->profileFor($pid)->additionalColumnsFor($table);
            $states = json_decode((string)($translation['l10n_state'] ?? ''), true);
            $states = is_array($states) ? $states : [];
            $open = [];
            foreach ($columns as $column) {
                $value = (string)($source[$column] ?? '');
                if (trim($value) === '' || !array_key_exists($column, $translation)) {
                    continue;
                }
                if (in_array($states[$column] ?? '', self::SYNCHRONIZED_STATES, true)) {
                    continue;
                }
                if ((string)$translation[$column] === $value) {
                    $open[] = $column;
                }
            }
            if ($open !== []) {
                $plan[] = ['translation' => $translation, 'columns' => $open];
            }
        }

        if ($plan === []) {
            $io->success(sprintf('%s: no translation holds a source copy in the selected columns.', $table));
            return Command::SUCCESS;
        }

        $rows = array_map(fn (array $item): array => [
            (int)$item['translation']['uid'],
            (int)$item['translation'][$languageField],
            (int)$item['translation']['pid'],
            implode(', ', $item['columns']),
            $this->shorten((string)($sources[(int)$item['translation'][$pointerField]][$item['columns'][0]] ?? '')),
        ], $plan);
        $io->table(['uid', 'language', 'pid', 'columns', 'source text (first column)'], $rows);

        if ($dryRun) {
            $io->note(sprintf('Dry run: %d translation(s) of %s would be filled. Nothing written.', count($plan), $table));
            return Command::SUCCESS;
        }

        $changed = 0;
        try {
            foreach ($plan as $item) {
                $translation = $item['translation'];
                $uid = (int)$translation['uid'];
                $pid = (int)$translation['pid'];
                $languageId = (int)$translation[$languageField];
                $profile = $this->profileFor($pid);
                $targetLanguage = $this->languageNames->targetName($pid, $languageId);
                if ($targetLanguage === '') {
                    $io->warning(sprintf('%s:%d: no name for language %d, skipped.', $table, $uid, $languageId));
                    continue;
                }
                // Not forced: ColumnTranslator itself only touches columns that are
                // still empty or identical to the source.
                $this->columnTranslator->translateColumns(
                    $table,
                    $uid,
                    $item['columns'],
                    $profile,
                    $targetLanguage,
                    $this->glossaryProvider->glossaryBlock($profile->profileUid, $pid, $languageId),
                    '',
                );
                // ColumnTranslator writes the columns directly; a new tstamp lets
                // publishing workflows (changed = tstamp > last publish) see them.
                $after = $this->row($table, $uid);
                if ($after !== null && array_intersect_key($after, array_flip($item['columns'])) !== array_intersect_key($translation, array_flip($item['columns']))) {
                    if (isset($GLOBALS['TCA'][$table]['ctrl']['tstamp'])) {
                        $this->connectionPool->getConnectionForTable($table)
                            ->update($table, [$GLOBALS['TCA'][$table]['ctrl']['tstamp'] => time()], ['uid' => $uid]);
                    }
                    $changed++;
                }
            }
        } catch (LlmRequestException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        foreach ($this->context->getErrors() as $error) {
            $io->warning($error);
        }
        $io->success(sprintf('%d of %d translation(s) of %s filled.', $changed, count($plan), $table));
        return Command::SUCCESS;
    }

    /**
     * @param list<int> $languages
     * @param list<int> $pids
     * @return list<array<string, mixed>>
     */
    private function translations(string $table, string $languageField, string $pointerField, array $languages, array $pids): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')
            ->from($table)
            ->where(
                $qb->expr()->gt($languageField, 0),
                $qb->expr()->gt($pointerField, 0),
            )
            ->orderBy('pid')
            ->addOrderBy($languageField)
            ->addOrderBy('uid');
        $deleteField = (string)($GLOBALS['TCA'][$table]['ctrl']['delete'] ?? '');
        if ($deleteField !== '') {
            $qb->andWhere($qb->expr()->eq($deleteField, 0));
        }
        if ($languages !== []) {
            $qb->andWhere($qb->expr()->in($languageField, $qb->createNamedParameter($languages, Connection::PARAM_INT_ARRAY)));
        }
        if ($pids !== []) {
            $qb->andWhere($qb->expr()->in('pid', $qb->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)));
        }
        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<int> $uids
     * @return array<int, array<string, mixed>>
     */
    private function sources(string $table, array $uids): array
    {
        $sources = [];
        foreach (array_chunk(array_values($uids), 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('*')
                ->from($table)
                ->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $sources[(int)$row['uid']] = $row;
            }
        }
        return $sources;
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['*'], $table, ['uid' => $uid])
            ->fetchAssociative();
        return is_array($row) ? $row : null;
    }

    private function profileFor(int $pid): ResolvedProfile
    {
        return $this->profiles[$pid] ??= $this->resolver->resolveForPage($pid);
    }

    private function shorten(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');
        return mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '…' : $value;
    }
}
