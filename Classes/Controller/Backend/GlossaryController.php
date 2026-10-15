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

namespace Dreistein\DTranslate\Controller\Backend;

use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Service\GlossaryStore;
use Dreistein\DTranslate\Service\LanguageNameResolver;
use Dreistein\DTranslate\Service\ProfileStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Glossary view of the "Translation" module: one profile's term pairs as a
 * paged list with search and language filter.
 *
 * The glossary used to be an inline field of the profile record, which meant
 * FormEngine rendered every single entry — thousands of them on a grown
 * profile — before the form could be used at all. Here only one page is read;
 * an entry is created, edited and deleted through the standard record form
 * and DataHandler, so nothing about the data itself changed.
 */
final class GlossaryController
{
    private const TABLE = 'tx_dtranslate_glossary';
    private const PAGE_SIZES = [25, 50, 100, 200];
    private const DEFAULT_PAGE_SIZE = 50;
    /** Numbered page links on each side of the current page. */
    private const PAGE_WINDOW = 2;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ProfileStore $profileStore,
        private readonly GlossaryStore $glossaryStore,
        private readonly SiteFinder $siteFinder,
        private readonly LanguageNameResolver $languageNames,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        // Hidden profiles are listed too: their glossary is still worth editing.
        $profiles = $this->profileStore->listAll();
        $profileUid = (int)($query['profile'] ?? 0);
        $profile = null;
        foreach ($profiles as $row) {
            if ((int)$row['uid'] === $profileUid) {
                $profile = $row;
                break;
            }
        }
        if ($profile === null && $profiles !== []) {
            $profile = $profiles[0];
        }
        $profileUid = $profile !== null ? (int)$profile['uid'] : 0;

        // '' = every entry, '-1' = the ones for all target languages, else one language.
        $languageParam = trim((string)($query['language'] ?? ''));
        $languageId = $languageParam !== '' && is_numeric($languageParam) ? (int)$languageParam : null;
        $languageParam = $languageId === null ? '' : (string)$languageId;
        $search = trim((string)($query['search'] ?? ''));
        $size = (int)($query['size'] ?? self::DEFAULT_PAGE_SIZE);
        if (!in_array($size, self::PAGE_SIZES, true)) {
            $size = self::DEFAULT_PAGE_SIZE;
        }

        $total = $profileUid > 0 ? $this->glossaryStore->count($profileUid, $languageId, $search) : 0;
        $pageCount = max(1, (int)ceil($total / $size));
        $page = min(max(1, (int)($query['page'] ?? 1)), $pageCount);
        $offset = ($page - 1) * $size;
        $rows = $profileUid > 0 ? $this->glossaryStore->page($profileUid, $languageId, $search, $offset, $size) : [];

        $filters = ['profile' => $profileUid, 'language' => $languageParam, 'search' => $search, 'size' => $size];
        $listUrl = $this->listUrl($filters + ['page' => $page]);
        $languageNames = $this->languageNames($profile);

        $entries = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $language = (int)$row['target_language'];
            $entries[] = [
                'uid' => $uid,
                'sourceTerm' => (string)$row['source_term'],
                'targetTerm' => (string)$row['target_term'],
                'note' => (string)$row['note'],
                'hidden' => (bool)$row['hidden'],
                'language' => $language === -1
                    ? Messages::tr('glossary.languageAll')
                    : ($languageNames[$language] ?? sprintf('#%d', $language)),
                'confirm' => Messages::tr('glossary.deleteConfirm', (string)$row['source_term'], (string)$row['target_term']),
                'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [self::TABLE => [$uid => 'edit']],
                    'returnUrl' => $listUrl,
                ]),
                // The core's own DataHandler endpoint: deletes and comes back here.
                'deleteUrl' => (string)$this->uriBuilder->buildUriFromRoute('tce_db', [
                    'cmd' => [self::TABLE => [$uid => ['delete' => 1]]],
                    'redirect' => $listUrl,
                ]),
            ];
        }

        $defaults = [self::TABLE => ['profile' => $profileUid]];
        if ($languageId !== null) {
            $defaults[self::TABLE]['target_language'] = $languageId;
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Translation · Glossary');
        $view->assignMultiple([
            'assetVersion' => $this->assetVersion(),
            'profiles' => $profiles,
            'profile' => $profile,
            'profileUid' => $profileUid,
            'languageOptions' => $this->languageOptions($profileUid, $languageNames, $languageParam),
            'language' => $languageParam,
            'search' => $search,
            'sizeOptions' => array_map(static fn (int $s): array => ['value' => $s, 'selected' => $s === $size], self::PAGE_SIZES),
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'pageCount' => $pageCount,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $size, $total),
            'form' => $this->formTarget(),
            'pagination' => $this->pagination($filters, $page, $pageCount),
            'resetUrl' => $this->listUrl(['profile' => $profileUid]),
            'backUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate'),
            'newUrl' => $profileUid > 0 ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::TABLE => [0 => 'new']],
                'defVals' => $defaults,
                'returnUrl' => $listUrl,
            ]) : '',
            'profileEditUrl' => $profileUid > 0 ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_dtranslate_profile' => [$profileUid => 'edit']],
                'returnUrl' => $listUrl,
            ]) : '',
        ]);

        return $view->renderResponse('Backend/Glossary');
    }

    /**
     * Filter options: every entry, the ones for all target languages, then the
     * languages of the profile's site — each with its number of entries, so an
     * editor sees at a glance where the glossary is thin. Languages that only
     * exist in old entries (removed from the site since) stay selectable.
     *
     * @param array<int, string> $languageNames
     * @return list<array{value: string, label: string, selected: bool}>
     */
    private function languageOptions(int $profileUid, array $languageNames, string $current): array
    {
        $counts = $profileUid > 0 ? $this->glossaryStore->countByLanguage($profileUid) : [];
        $options = [
            ['value' => '', 'label' => sprintf('%s (%d)', Messages::tr('glossary.languageAny'), array_sum($counts))],
            ['value' => '-1', 'label' => sprintf('%s (%d)', Messages::tr('glossary.languageAll'), $counts[-1] ?? 0)],
        ];
        foreach ($languageNames as $id => $name) {
            $options[] = ['value' => (string)$id, 'label' => sprintf('%s · %d', $name, $counts[$id] ?? 0)];
        }
        foreach ($counts as $id => $count) {
            if ($id > 0 && !isset($languageNames[$id])) {
                $options[] = ['value' => (string)$id, 'label' => sprintf('#%d · %d', $id, $count)];
            }
        }
        foreach ($options as &$option) {
            $option['selected'] = $option['value'] === $current;
        }
        unset($option);
        return $options;
    }

    /**
     * Target languages of the profile's site, id => label; every site's
     * languages (deduplicated) for the default profile without a site. The
     * label is the title from the site configuration ("Englisch/USA") plus
     * the id: four English variants named "English" would be indistinguishable.
     *
     * @param array<string, mixed>|null $profile
     * @return array<int, string>
     */
    private function languageNames(?array $profile): array
    {
        $sites = [];
        $identifier = (string)($profile['site_identifier'] ?? '');
        if ($identifier !== '') {
            try {
                $sites = [$this->siteFinder->getSiteByIdentifier($identifier)];
            } catch (\Throwable) {
                // Site gone — fall through to every site.
            }
        }
        if ($sites === []) {
            $sites = $this->siteFinder->getAllSites();
        }
        $names = [];
        foreach ($sites as $site) {
            $defaultId = $site->getDefaultLanguage()->getLanguageId();
            foreach ($site->getAllLanguages() as $language) {
                $id = $language->getLanguageId();
                if ($id === $defaultId || isset($names[$id])) {
                    continue;
                }
                $title = trim($language->getTitle());
                if ($title === '') {
                    $title = $this->languageNames->nameOf($language);
                }
                $names[$id] = sprintf('%s (#%d)', $title, $id);
            }
        }
        ksort($names);
        return $names;
    }

    /**
     * A GET form drops the query string of its action URL, and the route's
     * token lives there — so the form posts to the bare path and carries the
     * route parameters as hidden fields.
     *
     * @return array{action: string, hidden: array<string, string>}
     */
    private function formTarget(): array
    {
        $uri = $this->uriBuilder->buildUriFromRoute('dtranslate.glossary');
        parse_str($uri->getQuery(), $hidden);
        return ['action' => $uri->getPath(), 'hidden' => array_map('strval', $hidden)];
    }

    /**
     * @param array<string, int|string> $params
     */
    private function listUrl(array $params): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(
            'dtranslate.glossary',
            array_filter($params, static fn ($value): bool => $value !== '' && $value !== null),
        );
    }

    /**
     * First/previous/next/last plus a window of numbered pages around the
     * current one, with the first and last page always present.
     *
     * @param array<string, int|string> $filters
     * @return array{first: string, prev: string, next: string, last: string, pages: list<array<string, mixed>>}
     */
    private function pagination(array $filters, int $page, int $pageCount): array
    {
        $url = fn (int $p): string => $this->listUrl($filters + ['page' => $p]);
        $pages = [];
        $last = 0;
        for ($p = 1; $p <= $pageCount; $p++) {
            if ($p !== 1 && $p !== $pageCount && abs($p - $page) > self::PAGE_WINDOW) {
                continue;
            }
            if ($p - $last > 1) {
                $pages[] = ['gap' => true];
            }
            $pages[] = ['page' => $p, 'url' => $url($p), 'current' => $p === $page];
            $last = $p;
        }
        return [
            'first' => $url(1),
            'prev' => $url(max(1, $page - 1)),
            'next' => $url(min($pageCount, $page + 1)),
            'last' => $url($pageCount),
            'pages' => $pages,
        ];
    }

    /** Cache-busting stamp, see TranslationModuleController::assetVersion(). */
    private function assetVersion(): string
    {
        $newest = 0;
        foreach (['Css/backend-module.css', 'JavaScript/glossary-list.js'] as $file) {
            $path = GeneralUtility::getFileAbsFileName('EXT:d_translate/Resources/Public/' . $file);
            $newest = max($newest, (int)@filemtime($path));
        }
        return (string)$newest;
    }
}
