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

namespace Dreistein\DTranslate\Service;

use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Rebuilds the slug of page translations that still carry the source page's
 * slug: the copy DataHandler makes when an editor clicks "Translate" or an
 * import creates the translation, which nobody rebuilt after the title was
 * translated. A translation run rebuilds such a slug for the page it works
 * on; this is the one-off repair for a whole site (`dtranslate:remap-anchors
 * --slugs`).
 *
 * plan() finds and prices the changes without writing — parents before
 * children, a child's new slug already carrying its parent's new one — and
 * apply() writes them through the DataHandler, the way an editor's change in
 * the page properties goes: the slug is made unique, history and reference
 * index are kept, the page cache is cleared, and EXT:redirects (when
 * installed and the site allows it) creates the redirects from the old URLs
 * and moves the subpages along.
 */
final class PageSlugRepairer
{
    private const TABLE = 'pages';
    private const FIELD = 'slug';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SlugGenerator $slugGenerator,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The page translations in $languageId of the given pages whose slug is
     * still byte-identical to the source page's, with the slug they would get.
     * `to` is null for a translation that cannot be rebuilt yet because its
     * title has not been translated either ("[Translate to …]").
     *
     * Uniqueness is checked against the database as it is, not against the
     * other planned slugs: two siblings planned to the same slug would be told
     * apart only by apply(), through the DataHandler.
     *
     * @param list<int> $pageIds default-language page uids, in any order
     * @return list<array{uid: int, pid: int, default: int, language: int, title: string, from: string, to: string|null}>
     */
    public function plan(array $pageIds, int $languageId): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0)));
        if ($pageIds === [] || $languageId <= 0) {
            return [];
        }
        $defaults = $this->defaultPages($pageIds);
        $translations = $this->translations(array_keys($defaults), $languageId);

        $planned = [];
        $plan = [];
        foreach ($this->parentsFirst($defaults) as $default) {
            $translation = $translations[(int)$default['uid']] ?? null;
            if ($translation === null) {
                continue;
            }
            $current = (string)$translation['slug'];
            if ($current === '' || $current !== (string)$default['slug']) {
                continue;
            }
            $entry = [
                'uid' => (int)$translation['uid'],
                'pid' => (int)$translation['pid'],
                'default' => (int)$default['uid'],
                'language' => $languageId,
                'title' => (string)$translation['title'],
                'from' => $current,
                'to' => null,
            ];
            if (str_starts_with((string)$translation['title'], '[Translate to')) {
                $plan[] = $entry;
                continue;
            }
            $to = $this->rebuiltSlug($translation, $planned);
            if ($to === '' || $to === $current) {
                continue;
            }
            $entry['to'] = $to;
            $planned[(int)$translation['uid']] = $to;
            $plan[] = $entry;
        }
        return $plan;
    }

    /**
     * Write the planned slugs, in plan order. Each slug is built again from
     * the record as it is at that moment: an earlier entry may have changed
     * the parent's slug — or had it made unique — since the plan was made,
     * and EXT:redirects may already have moved this page along with it.
     *
     * @param list<array{uid: int, from: string, to: string|null}> $plan from plan()
     * @return list<array{uid: int, from: string, to: string, error: string}> one entry per written (or failed) page
     */
    public function apply(array $plan): array
    {
        $written = [];
        foreach ($plan as $entry) {
            if (($entry['to'] ?? null) === null) {
                continue;
            }
            $uid = (int)$entry['uid'];
            $record = $this->row($uid);
            if ($record === null) {
                continue;
            }
            $current = (string)$record[self::FIELD];
            $to = $this->slugGenerator->generate(self::TABLE, self::FIELD, $record);
            if ($to === '' || $to === $current) {
                // Nothing left to write: a parent's change already carried
                // this page to its new slug (EXT:redirects moves subpages).
                if ($current !== (string)$entry['from']) {
                    $written[] = ['uid' => $uid, 'from' => (string)$entry['from'], 'to' => $current, 'error' => ''];
                }
                continue;
            }

            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([self::TABLE => [$uid => [self::FIELD => $to]]], []);
            $dataHandler->process_datamap();
            if ($dataHandler->errorLog !== []) {
                $error = implode('; ', $dataHandler->errorLog);
                $this->logger->error('d-translate: page slug could not be rebuilt', ['uid' => $uid, 'errors' => $dataHandler->errorLog]);
                $written[] = ['uid' => $uid, 'from' => (string)$entry['from'], 'to' => $current, 'error' => $error];
                continue;
            }
            $after = $this->row($uid);
            $final = (string)($after[self::FIELD] ?? $to);
            $written[] = ['uid' => $uid, 'from' => (string)$entry['from'], 'to' => $final, 'error' => ''];
            $this->logger->info('d-translate: page slug rebuilt from the translated title', [
                'uid' => $uid,
                'language' => (int)($record['sys_language_uid'] ?? 0),
                'from' => (string)$entry['from'],
                'to' => $final,
            ]);
        }
        return $written;
    }

    /**
     * The slug core would generate for the translation now, with a parent's
     * planned slug in place of the one still in the database. Mirrors
     * SlugHelper::generate(): the parent prefix is the slug of the nearest
     * ancestor that is a real page, in the translation's language or one of
     * its fallbacks.
     *
     * @param array<string, mixed> $translation
     * @param array<int, string> $planned translation uid => planned slug
     */
    private function rebuiltSlug(array $translation, array $planned): string
    {
        $pid = (int)($translation['pid'] ?? 0);
        if ($pid === 0 || !empty($translation['is_siteroot'])) {
            return '/';
        }
        $own = $this->slugGenerator->build(self::TABLE, self::FIELD, $translation, false);
        $parent = $this->parentPage($pid, (int)($translation['sys_language_uid'] ?? 0));
        $parentSlug = $parent === null ? '' : ($planned[(int)$parent['uid']] ?? (string)($parent['slug'] ?? ''));
        if ($parent !== null && $parentSlug === '') {
            // A parent without a slug of its own: core builds one from its
            // title on the fly, so let it build the whole thing.
            return $this->slugGenerator->generate(self::TABLE, self::FIELD, $translation);
        }
        $prefix = trim($parentSlug, '/');
        $slug = $this->slugGenerator->sanitize(self::TABLE, self::FIELD, $prefix !== '' ? $prefix . $own : $own);
        return $this->slugGenerator->unique(self::TABLE, self::FIELD, $slug, $translation);
    }

    /**
     * The page whose slug prefixes a page under $pid, in $languageId or one of
     * its fallback languages — as SlugHelper resolves it: the nearest ancestor
     * that is neither spacer, recycler nor folder.
     *
     * @return array<string, mixed>|null
     */
    private function parentPage(int $pid, int $languageId): ?array
    {
        $rootLine = BackendUtility::BEgetRootLine($pid, '', true, ['nav_title']);
        $excluded = [PageRepository::DOKTYPE_SPACER, PageRepository::DOKTYPE_RECYCLER, PageRepository::DOKTYPE_SYSFOLDER];
        do {
            $parent = array_shift($rootLine);
        } while ($rootLine !== [] && in_array((int)($parent['doktype'] ?? 0), $excluded, true));
        if (!is_array($parent)) {
            return null;
        }
        $languageIds = [$languageId];
        try {
            $languageIds = array_merge($languageIds, $this->siteFinder->getSiteByPageId($pid)->getLanguageById($languageId)->getFallbackLanguageIds());
        } catch (SiteNotFoundException | \InvalidArgumentException) {
            // No site or no such language: the language itself has to do.
        }
        foreach ($languageIds as $id) {
            $localized = BackendUtility::getRecordLocalization(self::TABLE, (int)$parent['uid'], $id);
            if (!empty($localized)) {
                return reset($localized);
            }
        }
        return $parent;
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, array<string, mixed>> uid => default-language page
     */
    private function defaultPages(array $pageIds): array
    {
        $pages = [];
        foreach (array_chunk($pageIds, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('uid', 'pid', 'slug', 'title')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', 0),
                    $qb->expr()->eq('deleted', 0)
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $pages[(int)$row['uid']] = $row;
            }
        }
        return $pages;
    }

    /**
     * @param list<int> $pageIds default-language page uids
     * @return array<int, array<string, mixed>> default page uid => its translation in $languageId
     */
    private function translations(array $pageIds, int $languageId): array
    {
        $translations = [];
        foreach (array_chunk($pageIds, 1000) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('*')
                ->from(self::TABLE)
                ->where(
                    $qb->expr()->in('l10n_parent', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
                    $qb->expr()->eq('deleted', 0)
                )
                ->orderBy('uid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                // The first (lowest uid) translation counts, should there be two.
                $translations[(int)$row['l10n_parent']] ??= $row;
            }
        }
        return $translations;
    }

    /**
     * The pages ordered so that every page comes after its parent (within
     * the set); siblings by uid.
     *
     * @param array<int, array<string, mixed>> $pages uid => page
     * @return list<array<string, mixed>>
     */
    private function parentsFirst(array $pages): array
    {
        $depth = [];
        $depthOf = static function (int $uid) use (&$depthOf, &$depth, $pages): int {
            if (isset($depth[$uid])) {
                return $depth[$uid];
            }
            $depth[$uid] = 0; // a cycle, should there be one, ends here
            $pid = (int)($pages[$uid]['pid'] ?? 0);
            return $depth[$uid] = isset($pages[$pid]) ? $depthOf($pid) + 1 : 0;
        };
        foreach (array_keys($pages) as $uid) {
            $depthOf((int)$uid);
        }
        uasort($pages, static fn (array $a, array $b): int => ($depth[(int)$a['uid']] <=> $depth[(int)$b['uid']]) ?: ((int)$a['uid'] <=> (int)$b['uid']));
        return array_values($pages);
    }

    /** @return array<string, mixed>|null */
    private function row(int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }
}
