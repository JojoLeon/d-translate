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
use Dreistein\DTranslate\Service\LanguageCatalog;
use Dreistein\DTranslate\Service\ProfileStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
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
        private readonly LanguageCatalog $languageCatalog,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    public function list(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        // Hidden profiles are listed too: their glossary is still worth editing.
        // The entries shared by every profile (profile 0) are listed like a
        // profile of their own, first in the list.
        $profiles = array_map(
            static fn (array $p): array => $p + ['sites' => ProfileStore::sitesLabel($p)],
            $this->profileStore->listAll(),
        );
        array_unshift($profiles, [
            'uid' => GlossaryStore::SHARED_PROFILE,
            'title' => Messages::tr('glossary.sharedProfile'),
            'site_identifier' => '',
            'sites' => '',
            'hidden' => 0,
        ]);
        $requested = isset($query['profile']) ? (int)$query['profile'] : null;
        $profile = null;
        foreach ($profiles as $row) {
            if ($requested !== null && (int)$row['uid'] === $requested) {
                $profile = $row;
                break;
            }
        }
        // Default: the first real profile; the shared list only when there is none.
        $profile ??= $profiles[1] ?? $profiles[0];
        $profileUid = (int)$profile['uid'];

        // '' = every entry, otherwise entries stored with exactly this key
        // ('-1' every language, 'en', 'en-GB' or a site language id).
        $languageParam = trim((string)($query['language'] ?? ''));
        $languageKey = $languageParam !== '' ? $languageParam : null;
        $search = trim((string)($query['search'] ?? ''));
        $size = (int)($query['size'] ?? self::DEFAULT_PAGE_SIZE);
        if (!in_array($size, self::PAGE_SIZES, true)) {
            $size = self::DEFAULT_PAGE_SIZE;
        }

        $total = $this->glossaryStore->count($profileUid, $languageKey, $search);
        $pageCount = max(1, (int)ceil($total / $size));
        $page = min(max(1, (int)($query['page'] ?? 1)), $pageCount);
        $offset = ($page - 1) * $size;
        $rows = $this->glossaryStore->page($profileUid, $languageKey, $search, $offset, $size);

        $filters = ['profile' => $profileUid, 'language' => $languageParam, 'search' => $search, 'size' => $size];
        $listUrl = $this->listUrl($filters + ['page' => $page]);

        $entries = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $entries[] = [
                'uid' => $uid,
                'sourceTerm' => (string)$row['source_term'],
                'targetTerm' => (string)$row['target_term'],
                'note' => (string)$row['note'],
                'hidden' => (bool)$row['hidden'],
                'language' => $this->keyLabel((string)$row['target_language']),
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
        if ($languageKey !== null) {
            $defaults[self::TABLE]['target_language'] = $languageKey;
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Translation · Glossary');
        $view->assignMultiple([
            'assetVersion' => $this->assetVersion(),
            'profiles' => $profiles,
            'profile' => $profile,
            'profileUid' => $profileUid,
            'languageOptions' => $this->languageOptions($profileUid, $languageParam),
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
            'newUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [self::TABLE => [0 => 'new']],
                'defVals' => $defaults,
                'returnUrl' => $listUrl,
            ]),
            'profileEditUrl' => $profileUid > 0 ? (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_dtranslate_profile' => [$profileUid => 'edit']],
                'returnUrl' => $listUrl,
            ]) : '',
        ]);

        return $view->renderResponse('Backend/Glossary');
    }

    /**
     * Filter options in groups: every entry and "every target language" first,
     * then the languages, the regional variants and the single site language
     * ids the installation has — each with its number of entries — plus the
     * keys only old entries still carry (a site language removed since).
     *
     * @return list<array{label: string, options: list<array{value: string, label: string, selected: bool}>}>
     */
    private function languageOptions(int $profileUid, string $current): array
    {
        $counts = $this->glossaryStore->countByLanguage($profileUid);
        $option = static fn (string $value, string $label, int $count): array => [
            'value' => $value,
            'label' => sprintf('%s · %d', $label, $count),
            'selected' => $value === $current,
        ];
        $groups = [['label' => '', 'options' => [
            $option('', Messages::tr('glossary.languageAny'), array_sum($counts)),
            $option(LanguageCatalog::ALL, Messages::tr('glossary.languageAll'), $counts[LanguageCatalog::ALL] ?? 0),
        ]]];
        $known = ['', LanguageCatalog::ALL];
        $catalogue = $this->languageCatalog->catalogue();
        foreach ([['glossary.group.languages', 'languages'], ['glossary.group.variants', 'variants'], ['glossary.group.ids', 'ids']] as [$labelKey, $part]) {
            $options = [];
            foreach (array_keys($catalogue[$part]) as $key) {
                $key = (string)$key;
                $options[] = $option($key, $this->languageCatalog->label($key), $counts[$key] ?? 0);
                $known[] = $key;
            }
            if ($options !== []) {
                $groups[] = ['label' => Messages::tr($labelKey), 'options' => $options];
            }
        }
        $other = [];
        foreach ($counts as $key => $count) {
            if (!in_array((string)$key, $known, true)) {
                $other[] = $option((string)$key, $this->keyLabel((string)$key), $count);
            }
        }
        if ($other !== []) {
            $groups[] = ['label' => Messages::tr('glossary.group.other'), 'options' => $other];
        }
        return $groups;
    }

    /** What an entry applies to, as the list shows it. */
    private function keyLabel(string $key): string
    {
        if (LanguageCatalog::specificity($key) === 0) {
            return Messages::tr('glossary.languageAll');
        }
        $label = $this->languageCatalog->label($key);
        return $label !== '' ? $label : $key;
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
