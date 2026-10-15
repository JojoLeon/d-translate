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

use Dreistein\DTranslate\Service\GlossaryStore;
use Dreistein\DTranslate\Service\LanguageCatalog;
use Dreistein\DTranslate\Service\ProfileStore;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Collapses a glossary that was kept per regional variant into one entry per
 * language — the entry made for every site language id of English becomes a
 * single 'en' entry — and, on request, entries identical in every profile
 * into one shared entry.
 *
 * Nothing is lost: the entries that give way are marked deleted and sit in
 * the recycler; an entry whose variants disagree on the translation is left
 * exactly as it is and reported.
 *
 *   vendor/bin/typo3 dtranslate:glossary-consolidate --dry-run
 *   vendor/bin/typo3 dtranslate:glossary-consolidate --profile=1 --merge-profiles
 */
final class GlossaryConsolidateCommand extends Command
{
    public function __construct(
        private readonly GlossaryStore $store,
        private readonly LanguageCatalog $catalog,
        private readonly ProfileStore $profiles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                "Collapse entries kept once per regional variant (en-US, en-GB, …) or per site language id into one entry\n"
                . "per language ('en'). Variants that disagree on the translation are left alone and listed.\n"
                . "--merge-profiles afterwards turns entries identical in every profile into one entry shared by all.\n"
                . "Entries that give way are marked deleted (recycler), never removed. --dry-run writes nothing."
            )
            ->addOption('profile', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'profile uid; repeatable (default: every profile)')
            ->addOption('merge-profiles', null, InputOption::VALUE_NONE, 'also merge entries identical in every profile into one shared entry')
            ->addOption('complete-only', null, InputOption::VALUE_NONE, 'collapse a term only when every regional variant of the language has it')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'list what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $completeOnly = (bool)$input->getOption('complete-only');
        if ($dryRun) {
            $io->note('Dry run: nothing is written.');
        }

        $allProfiles = array_map(static fn (array $p): int => (int)$p['uid'], $this->profiles->listAll());
        $selected = array_values(array_unique(array_map('intval', (array)$input->getOption('profile'))));
        foreach ($selected as $uid) {
            if ($uid !== GlossaryStore::SHARED_PROFILE && !in_array($uid, $allProfiles, true)) {
                $io->error(sprintf('There is no profile %d. Known profiles: %s', $uid, implode(', ', $allProfiles)));
                return Command::FAILURE;
            }
        }
        $profileUids = $selected !== [] ? $selected : array_merge([GlossaryStore::SHARED_PROFILE], $allProfiles);

        $rows = [];
        foreach ($this->store->allForProfiles($profileUids) as $row) {
            if ((int)$row['hidden'] === 1) {
                continue; // a disabled entry is an editor's open question, not ours to merge
            }
            $rows[(int)$row['uid']] = $row;
        }

        // ---- 1. regional variants → one entry per language
        $summary = [];
        $conflicts = [];
        foreach ($profileUids as $profileUid) {
            $buckets = [];
            foreach ($rows as $row) {
                if ((int)$row['profile'] !== $profileUid) {
                    continue;
                }
                $key = (string)$row['target_language'];
                $language = $this->catalog->languageCodeOf($key);
                if ($language === '' || LanguageCatalog::specificity($key) === 0) {
                    continue; // every language, or an id no site knows
                }
                $buckets[$language . '|' . mb_strtolower(trim((string)$row['source_term']))][] = $row;
            }

            $collapsed = 0;
            $removed = 0;
            $partial = 0;
            foreach ($buckets as $bucketKey => $bucket) {
                $language = explode('|', $bucketKey, 2)[0];
                if (count($bucket) < 2 && LanguageCatalog::specificity((string)$bucket[0]['target_language']) === 1) {
                    continue; // already a language entry on its own
                }
                $translations = array_unique(array_map(
                    static fn (array $r): string => trim((string)$r['target_term']) . "\0" . trim((string)$r['note']),
                    $bucket,
                ));
                if (count($translations) > 1) {
                    $conflicts[] = [$profileUid, $bucket[0]['source_term'], $this->describe($bucket)];
                    continue;
                }
                $covered = [];
                $languageWide = null;
                foreach ($bucket as $r) {
                    $key = (string)$r['target_language'];
                    if (LanguageCatalog::specificity($key) === 1) {
                        $languageWide ??= $r;
                        continue;
                    }
                    $covered[$this->catalog->variantOf($key)] = true;
                }
                $complete = $languageWide !== null
                    || array_diff($this->catalog->variantsOf($language), array_keys($covered)) === [];
                if (!$complete) {
                    $partial++;
                    if ($completeOnly) {
                        continue;
                    }
                }
                // Keep the language-wide entry if there is one, else the oldest.
                $keeper = $languageWide ?? $bucket[0];
                foreach ($bucket as $r) {
                    if ((int)$r['uid'] < (int)$keeper['uid'] && $languageWide === null) {
                        $keeper = $r;
                    }
                }
                $giveWay = [];
                foreach ($bucket as $r) {
                    if ((int)$r['uid'] !== (int)$keeper['uid']) {
                        $giveWay[] = (int)$r['uid'];
                    }
                }
                if ($giveWay === [] && (string)$keeper['target_language'] === $language) {
                    continue;
                }
                $collapsed++;
                $removed += count($giveWay);
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  profile %d: "%s" → %s (%d entr%s give way)', $profileUid, $keeper['source_term'], $language, count($giveWay), count($giveWay) === 1 ? 'y' : 'ies'));
                }
                if (!$dryRun) {
                    if ((string)$keeper['target_language'] !== $language) {
                        $this->store->update((int)$keeper['uid'], ['target_language' => $language]);
                    }
                    $this->store->softDelete($giveWay);
                }
                // Keep the in-memory picture current for the profile merge below.
                $rows[(int)$keeper['uid']]['target_language'] = $language;
                foreach ($giveWay as $uid) {
                    unset($rows[$uid]);
                }
            }
            $summary[] = [(string)$profileUid, (string)$collapsed, (string)$removed, (string)$partial];
        }

        $io->table(['Profile', 'Terms collapsed to one language entry', 'Entries giving way', 'Terms with only some variants'], $summary);
        if ($conflicts !== []) {
            $io->section(sprintf('%d term(s) left alone — their variants disagree on the translation:', count($conflicts)));
            $io->table(['Profile', 'Source term', 'Variants'], array_slice($conflicts, 0, 50));
            if (count($conflicts) > 50) {
                $io->writeln(sprintf('  … and %d more', count($conflicts) - 50));
            }
        }

        // ---- 2. identical in every profile → one shared entry
        if ((bool)$input->getOption('merge-profiles')) {
            $real = array_values(array_filter($profileUids, static fn (int $uid): bool => $uid > 0));
            if ($selected !== [] && count($real) < count($allProfiles)) {
                $io->warning('--merge-profiles merges across every profile; it is skipped because only some were selected.');
            } else {
                $byIdentity = [];
                foreach ($rows as $row) {
                    if ((int)$row['profile'] <= 0) {
                        continue;
                    }
                    $identity = implode("\0", [
                        mb_strtolower(trim((string)$row['source_term'])),
                        trim((string)$row['target_term']),
                        trim((string)$row['note']),
                        (string)$row['target_language'],
                    ]);
                    $byIdentity[$identity][(int)$row['profile']] ??= $row;
                }
                $merged = 0;
                $removed = 0;
                $partial = 0;
                foreach ($byIdentity as $perProfile) {
                    if (count($perProfile) < count($allProfiles)) {
                        $partial++;
                        continue;
                    }
                    ksort($perProfile);
                    $keeper = array_shift($perProfile);
                    $giveWay = array_map(static fn (array $r): int => (int)$r['uid'], array_values($perProfile));
                    $merged++;
                    $removed += count($giveWay);
                    if (!$dryRun) {
                        $this->store->update((int)$keeper['uid'], ['profile' => GlossaryStore::SHARED_PROFILE]);
                        $this->store->softDelete($giveWay);
                    }
                }
                $io->table(['Entries shared by every profile', 'Entries giving way', 'Entries in only some profiles'], [[(string)$merged, (string)$removed, (string)$partial]]);
            }
        }

        $io->{$dryRun ? 'note' : 'success'}($dryRun ? 'Dry run finished — run again without --dry-run to apply.' : 'Glossary consolidated; the entries that gave way are in the recycler.');
        return Command::SUCCESS;
    }

    /** @param list<array<string, mixed>> $bucket */
    private function describe(array $bucket): string
    {
        $parts = [];
        foreach ($bucket as $r) {
            $parts[] = sprintf('%s: %s', $r['target_language'], $r['target_term']);
        }
        return implode(' | ', $parts);
    }
}
