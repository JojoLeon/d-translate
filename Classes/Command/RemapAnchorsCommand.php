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

use Dreistein\DTranslate\Service\AnchorLinkRemapper;
use Dreistein\DTranslate\Service\PageSlugRepairer;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * One-off repair of translations made before the anchor remapper and the slug
 * rebuild existed: points the element anchors of page links in every
 * translation of a site at the translated elements and, with --slugs, rebuilds
 * page slugs still copied from the source page. No LLM call is involved.
 *
 *   vendor/bin/typo3 dtranslate:remap-anchors --dry-run
 *   vendor/bin/typo3 dtranslate:remap-anchors --site=main --language=2 --slugs
 *   vendor/bin/typo3 dtranslate:remap-anchors --page=42 --slugs
 */
final class RemapAnchorsCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly AnchorLinkRemapper $remapper,
        private readonly PageSlugRepairer $slugRepairer,
        private readonly CacheManager $cacheManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                "Points the element anchors of page links (t3://page?uid=…#…) in every translation\n"
                . "of the selected sites at the translated elements — the repair for translations\n"
                . "made before dTRANSLATE did this on every run. With --slugs, page translations whose\n"
                . "slug is still the copy of the source page's slug get a slug built from their\n"
                . "translated title; written through the DataHandler, so EXT:redirects creates its\n"
                . "redirects and moves the subpages along, as for an editor's change.\n"
                . "--dry-run lists every record and anchor (or slug) that would change and writes nothing."
            )
            ->addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'site identifier; repeatable (default: every site)')
            ->addOption('page', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'default-language page uid: only this page instead of the whole site; repeatable')
            ->addOption('language', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'target sys_language_uid; repeatable (default: every language of the site)')
            ->addOption('slugs', null, InputOption::VALUE_NONE, 'also rebuild page slugs still identical to the source page\'s slug')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'list what would change, write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool)$input->getOption('dry-run');
        $withSlugs = (bool)$input->getOption('slugs');
        $languageFilter = array_map('intval', (array)$input->getOption('language'));

        $pageFilter = array_map('intval', (array)$input->getOption('page'));
        $sites = $pageFilter !== []
            ? $this->sitesOfPages($pageFilter, $io)
            : $this->selectedSites((array)$input->getOption('site'), $io);
        if ($sites === null) {
            return Command::FAILURE;
        }

        Bootstrap::initializeBackendAuthentication();

        if ($dryRun) {
            $io->note('Dry run: nothing is written.');
        }

        $rows = [];
        $totalRecords = 0;
        $totalSlugs = 0;
        $failed = 0;
        foreach ($sites as $site) {
            $pageIds = $pageFilter !== []
                ? array_values(array_filter($pageFilter, fn (int $id): bool => $this->siteOfPage($id)?->getIdentifier() === $site->getIdentifier()))
                : $this->pagesOfSite($site);
            foreach ($site->getLanguages() as $language) {
                $languageId = $language->getLanguageId();
                if ($languageId === 0 || ($languageFilter !== [] && !in_array($languageId, $languageFilter, true))) {
                    continue;
                }
                $io->section(sprintf('%s — %s (language %d)', $site->getIdentifier(), $language->getTitle(), $languageId));

                $records = $this->remapper->remapOnPages($pageIds, $languageId, $dryRun);
                $changes = $this->remapper->takeChanges();
                $this->listAnchorChanges($io, $changes);
                if (!$dryRun) {
                    $this->clearPageCaches($changes);
                }

                $slugs = 0;
                if ($withSlugs) {
                    $plan = $this->slugRepairer->plan($pageIds, $languageId);
                    $slugs = $this->listSlugPlan($io, $plan);
                    if (!$dryRun && $slugs > 0) {
                        $written = $this->slugRepairer->apply($plan);
                        $failed += $this->listSlugResult($io, $written);
                        $slugs = count($written);
                    }
                }

                if ($records === 0 && $slugs === 0) {
                    $io->writeln('  nothing to do');
                }
                $totalRecords += $records;
                $totalSlugs += $slugs;
                $rows[] = [$site->getIdentifier(), sprintf('%s (%d)', $language->getTitle(), $languageId), $records, $withSlugs ? $slugs : '–'];
            }
        }

        if ($rows === []) {
            $io->warning('No site language matched the selection.');
            return Command::SUCCESS;
        }
        $io->table(['Site', 'Language', 'Records with anchors', 'Page slugs'], $rows);
        $io->{$dryRun ? 'note' : 'success'}(sprintf(
            '%s: %d record(s) with page-link anchors%s.',
            $dryRun ? 'Would change' : 'Changed',
            $totalRecords,
            $withSlugs ? sprintf(', %d page slug(s)', $totalSlugs) : ''
        ));
        if ($failed > 0) {
            $io->error(sprintf('%d page slug(s) could not be written, see above.', $failed));
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * The sites named by --site, or every site; null (after a message) when a
     * named site does not exist.
     *
     * @param list<string> $identifiers
     * @return list<Site>|null
     */
    private function selectedSites(array $identifiers, SymfonyStyle $io): ?array
    {
        if ($identifiers === []) {
            $sites = array_values($this->siteFinder->getAllSites());
            usort($sites, static fn (Site $a, Site $b): int => strcmp($a->getIdentifier(), $b->getIdentifier()));
            return $sites;
        }
        $sites = [];
        foreach ($identifiers as $identifier) {
            try {
                $sites[] = $this->siteFinder->getSiteByIdentifier((string)$identifier);
            } catch (SiteNotFoundException) {
                $io->error(sprintf('There is no site "%s". Known sites: %s', $identifier, implode(', ', array_keys($this->siteFinder->getAllSites()))));
                return null;
            }
        }
        return $sites;
    }

    /**
     * The sites the pages of --page belong to, each once; null (after a
     * message) when a page has none.
     *
     * @param list<int> $pageIds
     * @return list<Site>|null
     */
    private function sitesOfPages(array $pageIds, SymfonyStyle $io): ?array
    {
        $sites = [];
        foreach ($pageIds as $pageId) {
            $site = $this->siteOfPage($pageId);
            if ($site === null) {
                $io->error(sprintf('Page %d does not exist or belongs to no site.', $pageId));
                return null;
            }
            $sites[$site->getIdentifier()] = $site;
        }
        return array_values($sites);
    }

    private function siteOfPage(int $pageId): ?Site
    {
        try {
            return $pageId > 0 ? $this->siteFinder->getSiteByPageId($pageId) : null;
        } catch (SiteNotFoundException) {
            return null;
        }
    }

    /**
     * Every default-language page of the site's tree, folders included (their
     * records carry links too). The root of another site inside the tree, and
     * everything below it, belongs to that site's run.
     *
     * @return list<int>
     */
    private function pagesOfSite(Site $site): array
    {
        $root = $site->getRootPageId();
        $ids = [$root];
        $queue = [$root];
        while ($queue !== []) {
            $batch = array_splice($queue, 0, 500);
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('uid', 'is_siteroot')
                ->from('pages')
                ->where(
                    $qb->expr()->in('pid', $qb->createNamedParameter($batch, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', 0),
                    $qb->expr()->eq('deleted', 0)
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                if ((int)$row['is_siteroot'] === 1) {
                    continue;
                }
                $ids[] = (int)$row['uid'];
                $queue[] = (int)$row['uid'];
            }
        }
        return $ids;
    }

    /**
     * The anchors are written straight to the records, so the pages rendering
     * them would keep serving the old links from the page cache.
     *
     * @param list<array{page: int, columns: array<string, mixed>}> $changes
     */
    private function clearPageCaches(array $changes): void
    {
        $tags = [];
        foreach ($changes as $change) {
            if ($change['columns'] !== [] && $change['page'] > 0) {
                $tags['pageId_' . $change['page']] = true;
            }
        }
        if ($tags !== []) {
            $this->cacheManager->flushCachesInGroupByTags('pages', array_keys($tags));
        }
    }

    /**
     * @param list<array{table: string, uid: int, pid: int, page: int, language: int, columns: array<string, list<array{0: string, 1: string}>>, unresolved: array<string, list<array{0: string, 1: string}>>}> $changes
     */
    private function listAnchorChanges(SymfonyStyle $io, array $changes): void
    {
        foreach ($changes as $change) {
            $columns = [];
            foreach ($change['columns'] as $column => $pairs) {
                $columns[] = $column . ': ' . implode(', ', array_map(static fn (array $pair): string => $pair[0] . ' → ' . $pair[1], $pairs));
            }
            foreach ($change['unresolved'] as $column => $anchors) {
                $columns[] = $column . ': ' . implode(', ', array_map(
                    static fn (array $anchor): string => sprintf('%s left alone (%s)', $anchor[0], $anchor[1] === 'missing' ? 'no such element' : 'element not translated here'),
                    $anchors
                ));
            }
            $io->writeln(sprintf('  %s:%d (pid %d)  %s', $change['table'], $change['uid'], $change['pid'], implode('; ', $columns)));
        }
    }

    /**
     * @param list<array{uid: int, pid: int, default: int, language: int, title: string, from: string, to: string|null}> $plan
     * @return int number of slugs that would change
     */
    private function listSlugPlan(SymfonyStyle $io, array $plan): int
    {
        $count = 0;
        foreach ($plan as $entry) {
            if ($entry['to'] === null) {
                $io->writeln(sprintf('  pages:%d  "%s"  %s  — left alone: the title is not translated yet', $entry['uid'], $entry['title'], $entry['from']));
                continue;
            }
            $count++;
            $io->writeln(sprintf('  pages:%d  "%s"  %s → %s', $entry['uid'], $entry['title'], $entry['from'], $entry['to']));
        }
        return $count;
    }

    /**
     * @param list<array{uid: int, from: string, to: string, error: string}> $written
     * @return int number of failures
     */
    private function listSlugResult(SymfonyStyle $io, array $written): int
    {
        $failed = 0;
        foreach ($written as $result) {
            if ($result['error'] !== '') {
                $failed++;
                $io->writeln(sprintf('  <error>pages:%d  %s  not written: %s</error>', $result['uid'], $result['from'], $result['error']));
            } elseif ($io->isVerbose()) {
                $io->writeln(sprintf('  pages:%d  written: %s → %s', $result['uid'], $result['from'], $result['to']));
            }
        }
        return $failed;
    }
}
