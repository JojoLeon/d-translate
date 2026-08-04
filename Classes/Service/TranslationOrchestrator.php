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

use Dreistein\DTranslate\Configuration\ExtensionSettings;
use Dreistein\DTranslate\Llm\ResolvedProfile;
use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Translation\ProcessingContext;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Drives a translation run end-to-end: resolve the profile, arm the
 * ProcessingContext, run a real DataHandler "localize" (so everything flows
 * through the field hook), then disarm. The single code path behind every
 * trigger — CLI command, backend module, and (later) the scheduler.
 *
 * The caller must have a backend user in $GLOBALS['BE_USER'] (the BE module
 * always does; the CLI command calls Bootstrap::initializeBackendAuthentication()).
 */
final class TranslationOrchestrator
{
    public function __construct(
        private readonly ProcessingContext $context,
        private readonly ProfileLlmResolver $resolver,
        private readonly GlossaryProvider $glossaryProvider,
        private readonly InlineTranslator $inlineTranslator,
        private readonly ColumnTranslator $columnTranslator,
        private readonly SourceVisibility $sourceVisibility,
        private readonly FieldPolicy $fieldPolicy,
        private readonly TranslationService $translationService,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionSettings $settings,
        private readonly LanguageNameResolver $languageNames,
    ) {
    }

    /**
     * Tables that are never translated as a record of a page.
     *
     * sys_file_reference only ever exists as a child of another record —
     * localizing one directly attaches a second reference to the SOURCE record
     * instead of translating anything.
     *
     * `pages` would be the SUBPAGES of the selected page, because records are
     * collected by pid. Offering them is misleading: a run only ever collects
     * the content of the selected page, so a subpage would get a translated page
     * record with untranslated content. The selected page itself is translated
     * by the run's own page unit ({@see ensurePageTranslation}); a subpage is
     * translated by selecting it in the page tree.
     */
    private const NEVER_STANDALONE = ['sys_file_reference', 'pages'];

    public const PAGE_CREATED = 'created';
    public const PAGE_UPDATED = 'updated';
    public const PAGE_UNCHANGED = 'unchanged';

    /**
     * Standard SEO/meta columns of a page translated alongside the title.
     */
    private const PAGE_META_FIELDS = [
        'seo_title', 'keywords', 'description', 'abstract',
        'og_title', 'og_description', 'twitter_title', 'twitter_description',
    ];

    public function translateRecord(string $table, int $uid, int $targetLanguageId): TranslationRunResult
    {
        $row = $this->connectionPool->getConnectionForTable($table)
            ->select(['uid', 'pid'], $table, ['uid' => $uid])
            ->fetchAssociative();
        if ($row === false) {
            return TranslationRunResult::failure(sprintf('Record %s:%d not found.', $table, $uid));
        }
        $pid = (int)$row['pid'];
        $profile = $this->resolver->resolveForPage($pid);
        if ($profile->profileUid === 0) {
            // Nothing applies here: no site profile, no default profile. Saying so
            // beats the "no API key" message the extension fallback would produce.
            return TranslationRunResult::failure(Messages::tr('run.noProfile'));
        }
        if (trim($profile->llm->apiKey) === '') {
            return TranslationRunResult::failure('No API key on the resolved LLM configuration.');
        }
        if (!$profile->allowsTable($table)) {
            return TranslationRunResult::failure(sprintf('Table "%s" is not in the profile allowlist.', $table));
        }

        $pageContext = $this->buildPageContext($pid);
        $result = $this->run($profile, $pid, $targetLanguageId, [$table => [$uid]], $pageContext);
        $this->runInlineOn($table, $result->createdUids, $profile, $pid, $targetLanguageId, $pageContext);
        return $result;
    }

    /** Translate the whole page/folder (all selectable records of every allowed table). */
    public function translatePage(int $pageId, int $targetLanguageId): TranslationRunResult
    {
        $profile = $this->resolver->resolveForPage($pageId);
        $selection = [];
        foreach ($this->selectableRecords($pageId, $profile) as $table => $records) {
            $selection[$table] = array_keys($records);
        }
        return $this->translateSelection($pageId, $targetLanguageId, $selection);
    }

    /**
     * Translate the page record plus the selected records of every allowed,
     * localizable table on the page/folder (tt_content, news, references, …).
     * tt_content keeps its container handling; inline/IRRE children follow their
     * parent.
     *
     * @param array<string, list<int>> $selectedByTable table => checked uids
     */
    public function translateSelection(
        int $pageId,
        int $targetLanguageId,
        array $selectedByTable,
        ?bool $publishPage = null,
        bool $includePage = true,
        ?int $profileUid = null,
        bool $skipTranslated = false,
    ): TranslationRunResult {
        // null = no explicit choice (CLI, scheduler) → extension configuration.
        $publishPage ??= $this->settings->publishPages();
        $profile = $this->resolver->resolveForPage($pageId, $profileUid);
        if (trim($profile->llm->apiKey) === '') {
            return TranslationRunResult::failure(Messages::tr('llm.noApiKeyConfigured', $profile->llm->label));
        }
        if (trim($profile->sourceLanguage) === '') {
            // Without a source language the prompt would have to guess, and a
            // wrong guess quietly produces wrong translations.
            return TranslationRunResult::failure(Messages::tr('run.sourceLanguageUnknown'));
        }
        // Field-level failures have no way back to the module; they are parked
        // in the request-scoped context and picked up at the end of the run.
        $this->context->clearErrors();

        // Read the whole page once; injected as cached context into every field call.
        $pageContext = $this->buildPageContext($pageId);
        // Only the page-metadata unit of a run touches the page record; doing it
        // again for every record would be hundreds of pointless round trips.
        $pageStatus = $includePage
            ? $this->ensurePageTranslation($pageId, $targetLanguageId, $profile, $pageContext, $publishPage)
            : '';

        $createdUids = [];
        $errors = [];
        $requested = 0;
        $retranslated = 0;

        foreach ($this->selectableRecords($pageId, $profile) as $table => $records) {
            $checked = array_map('intval', $selectedByTable[$table] ?? []);
            if ($checked === []) {
                continue;
            }
            $beforeMap = $this->tableTranslationsBySource($table, $pageId, $targetLanguageId); // source => translation
            $beforeSources = array_keys($beforeMap);

            // Top-level records the editor selected that have no translation yet.
            $newTopLevel = array_values(array_diff(array_intersect(array_keys($records), $checked), $beforeSources));
            if ($newTopLevel !== []) {
                if ($table === 'tt_content') {
                    // Container-first ordering: b13 drops a child's localize command
                    // while its container is untranslated.
                    foreach ($this->orderByContainerLevels($newTopLevel, $records) as $levelUids) {
                        $result = $this->run($profile, $pageId, $targetLanguageId, ['tt_content' => $levelUids], $pageContext);
                        $createdUids = array_merge($createdUids, $result->createdUids);
                        $errors = array_merge($errors, $result->errors);
                        $requested += $result->requested;
                    }
                } else {
                    $result = $this->run($profile, $pageId, $targetLanguageId, [$table => $newTopLevel], $pageContext);
                    $createdUids = array_merge($createdUids, $result->createdUids);
                    $errors = array_merge($errors, $result->errors);
                    $requested += $result->requested;
                }

                // Translate every newly created translation (incl. cascade children).
                $newTranslations = [];
                foreach ($this->tableTranslationsBySource($table, $pageId, $targetLanguageId) as $sourceUid => $translationUid) {
                    if (!in_array($sourceUid, $beforeSources, true)) {
                        $newTranslations[] = $translationUid;
                    }
                }
                $this->runInlineOn($table, $newTranslations, $profile, $pageId, $targetLanguageId, $pageContext);
            }

            // Re-translate selected records that ALREADY have a translation
            // (force-overwrite all translatable fields from the source) — unless
            // the run was asked to leave existing translations alone. That is
            // the "skip translated" case: fill in what is missing on a page
            // without paying for, or overwriting, what an editor has already
            // reviewed.
            $retranslateTrans = [];
            if (!$skipTranslated) {
                foreach ($checked as $sourceUid) {
                    if (isset($beforeMap[$sourceUid])) {
                        $retranslateTrans[] = $beforeMap[$sourceUid];
                    }
                }
            }
            if ($retranslateTrans !== []) {
                $this->runInlineOn($table, $retranslateTrans, $profile, $pageId, $targetLanguageId, $pageContext, true);
                $retranslated += count($retranslateTrans);
            }
        }

        if ($createdUids === [] && $retranslated === 0) {
            return new TranslationRunResult(
                $this->context->getErrors() === [],
                requested: 0,
                errors: $this->context->getErrors(),
                message: Messages::tr('run.emptySelection'),
                pageStatus: $pageStatus,
            );
        }

        $errors = array_merge($errors, $this->context->getErrors());

        return new TranslationRunResult(
            ok: $errors === [] || $createdUids !== [] || $retranslated > 0,
            requested: $requested,
            retranslated: $retranslated,
            createdUids: $createdUids,
            errors: $errors,
            pageStatus: $pageStatus,
        );
    }

    /**
     * Localizable TCA tables (languageField + transOrigPointerField), restricted
     * to the profile's allowlist when set.
     *
     * @return list<string>
     */
    private function consideredTables(ResolvedProfile $profile): array
    {
        $localizable = [];
        foreach ($GLOBALS['TCA'] as $table => $tca) {
            $ctrl = $tca['ctrl'] ?? [];
            if (in_array($table, self::NEVER_STANDALONE, true)) {
                continue;
            }
            if (!empty($ctrl['languageField']) && !empty($ctrl['transOrigPointerField'])) {
                $localizable[] = $table;
            }
        }
        if ($profile->translateTables !== []) {
            $localizable = array_values(array_intersect($localizable, $profile->translateTables));
        }
        return $localizable;
    }

    /**
     * Selectable (non-IRRE-child) default-language records per allowed table on a
     * page/folder: table => (uid => container parent; 0 for non-container tables).
     *
     * @return array<string, array<int, int>>
     */
    private function selectableRecords(int $pageId, ResolvedProfile $profile): array
    {
        $result = [];
        foreach ($this->consideredTables($profile) as $table) {
            $records = $this->topLevelRecords($table, $pageId);
            if ($records !== []) {
                $result[$table] = $records;
            }
        }
        return $result;
    }

    /**
     * Top-level (non inline-child) default-language records of a table on a page.
     *
     * @return array<int, int> uid => tx_container_parent (0 unless tt_content container child)
     */
    private function topLevelRecords(string $table, int $pageId): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? 'sys_language_uid');
        $sortBy = (string)($ctrl['sortby'] ?? $ctrl['default_sortby'] ?? 'uid');
        $sortBy = preg_replace('/^ORDER BY\s+/i', '', $sortBy) ?: 'uid';
        $childFields = $this->inlineChildPointerFields($table);
        $hasContainer = $table === 'tt_content' && isset($GLOBALS['TCA']['tt_content']['columns']['tx_container_parent']);

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $constraints = [
            $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
            $qb->expr()->eq($languageField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $rows = $qb->select('*')
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            foreach ($childFields as $field) {
                if ((int)($row[$field] ?? 0) > 0) {
                    continue 2; // inline child → handled via parent cascade
                }
            }
            $map[(int)$row['uid']] = $hasContainer ? (int)($row['tx_container_parent'] ?? 0) : 0;
        }
        return $map;
    }

    /**
     * Columns that mark a record of $childTable as an inline/collection child of
     * another record (the foreign_field of any TCA inline relation pointing at
     * it). Such records are localized via their parent's cascade, so they are
     * excluded from the directly-localized top-level set.
     *
     * NOTE: b13's tx_container_parent is intentionally NOT included — container
     * children are separate records that ARE localized directly (just ordered
     * after their container); see topLevelRecords()/contentTreeForTable().
     *
     * @return list<string>
     */
    /**
     * The page's colPos values in the order its backend layout lists them.
     * Empty when no layout applies — the caller then keeps the numeric order.
     *
     * @return list<int>
     */
    private function columnOrderForPage(int $pageId): array
    {
        try {
            $layout = GeneralUtility::makeInstance(BackendLayoutView::class)->getBackendLayoutForPage($pageId);
        } catch (\Throwable) {
            return [];
        }
        if ($layout === null) {
            return [];
        }

        return array_values(array_map('intval', $layout->getStructure()['__colPosList'] ?? []));
    }

    private function inlineChildPointerFields(string $childTable): array
    {
        $fields = [];
        // TCA type "file" (TYPO3 v12+) is an inline relation with implicit
        // foreign_table/foreign_field. Without this branch FAL references look
        // like standalone records and get localized on their own — which
        // multiplies the references hanging on the default-language record.
        if ($childTable === 'sys_file_reference') {
            $fields[] = 'uid_foreign';
        }
        foreach ($GLOBALS['TCA'] as $tca) {
            foreach ($tca['columns'] ?? [] as $config) {
                $c = $config['config'] ?? [];
                if (($c['type'] ?? '') === 'inline'
                    && ($c['foreign_table'] ?? '') === $childTable
                    && !empty($c['foreign_field'])
                ) {
                    $fields[] = (string)$c['foreign_field'];
                }
            }
        }
        return array_values(array_unique($fields));
    }

    /**
     * The page's default-language content as a flat, depth-ordered tree for the
     * backend selection UI. Top-level entries (depth 0) are selectable units;
     * container/collection children are nested (depth > 0) and follow their parent.
     *
     * @return list<array{uid: int, depth: int, indent: int, selectable: bool, label: string}>
     */
    /**
     * Records on a page/folder grouped by table, for the backend selection UI.
     * One group per allowed, localizable table that has records; IRRE child
     * tables are excluded.
     *
     * @return list<array{table: string, titleKey: string, elements: list<array{table: string, uid: int, depth: int, indent: int, selectable: bool, label: string}>}>
     */
    /** Backend convenience: resolve the page's profile and return its record groups. */
    public function recordGroupsForPage(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }
        return $this->pageRecordTree($pageId, $this->resolver->resolveForPage($pageId));
    }

    public function pageRecordTree(int $pageId, ResolvedProfile $profile): array
    {
        if ($pageId <= 0) {
            return [];
        }
        $groups = [];
        foreach ($this->consideredTables($profile) as $table) {
            $elements = $this->contentTreeForTable($table, $pageId);
            if ($elements !== []) {
                $groups[] = [
                    'table' => $table,
                    'titleKey' => (string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table),
                    'elements' => $elements,
                ];
            }
        }
        return $groups;
    }

    /**
     * Depth-ordered tree of a single table's default-language records on a page.
     * Same-table container/collection children are nested; records that are
     * inline children of ANOTHER table (IRRE) are excluded entirely.
     *
     * @return list<array{table: string, uid: int, depth: int, indent: int, selectable: bool, label: string}>
     */
    private function contentTreeForTable(string $table, int $pageId): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? 'sys_language_uid');
        $labelField = (string)($ctrl['label'] ?? 'uid');
        // For the tree, b13 container children nest under their container, so the
        // container parent counts as a nesting pointer here (unlike for exclusion).
        $childFields = $this->inlineChildPointerFields($table);
        if ($table === 'tt_content' && isset($GLOBALS['TCA']['tt_content']['columns']['tx_container_parent'])) {
            $childFields[] = 'tx_container_parent';
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $constraints = [
            $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
            $qb->expr()->eq($languageField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $qb->select('*')
            ->from($table)
            ->where(...$constraints);
        // Without an explicit order the database returns the rows however it
        // likes, and the list came out in no order an editor could follow — a
        // hero element sat at position 52 of 61 and looked simply absent.
        $hasColPos = isset($GLOBALS['TCA'][$table]['columns']['colPos']);
        if ($hasColPos) {
            $qb->addOrderBy('colPos');
        }
        $sortBy = trim((string)($ctrl['sortby'] ?? ''));
        $qb->addOrderBy($sortBy !== '' ? $sortBy : 'uid');
        $rows = $qb->executeQuery()->fetchAllAssociative();

        // …and columns follow the backend layout, not the numeric colPos: a
        // hero in colPos 1 sits ABOVE the main column in colPos 0 in the page
        // module, so ordering by the number would contradict the page the
        // editor just looked at. PHP sorts are stable, so the colPos/sorting
        // order from the query survives inside each column.
        $columnOrder = $hasColPos ? $this->columnOrderForPage($pageId) : [];
        if ($columnOrder !== []) {
            usort($rows, static function (array $a, array $b) use ($columnOrder): int {
                $indexA = array_search((int)($a['colPos'] ?? 0), $columnOrder, true);
                $indexB = array_search((int)($b['colPos'] ?? 0), $columnOrder, true);
                // Columns the layout does not mention (container children) keep
                // to the back, where their container nests them anyway.
                return ($indexA === false ? PHP_INT_MAX : $indexA) <=> ($indexB === false ? PHP_INT_MAX : $indexB);
            });
        }

        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int)$row['uid']] = $row;
        }

        $parentOf = [];
        $childrenOf = [];
        foreach ($rows as $row) {
            $uid = (int)$row['uid'];
            $sameTableParent = 0;
            $foreignChild = false;
            foreach ($childFields as $field) {
                $value = (int)($row[$field] ?? 0);
                if ($value > 0) {
                    if (isset($byUid[$value])) {
                        $sameTableParent = $value; // container/collection child on this page → nest
                    } else {
                        $foreignChild = true; // IRRE child of another table
                    }
                    break;
                }
            }
            if ($foreignChild && $sameTableParent === 0) {
                continue; // do not list IRRE children of other tables
            }
            $parentOf[$uid] = $sameTableParent;
            $childrenOf[$sameTableParent][] = $uid;
        }

        $tree = [];
        $walk = function (int $uid, int $depth) use (&$walk, &$tree, &$childrenOf, $byUid, $table, $labelField): void {
            $row = $byUid[$uid];
            $text = trim((string)($row[$labelField] ?? ''));
            if ($text === '') {
                $text = trim((string)($row['CType'] ?? ''));
            }
            $tree[] = [
                'table' => $table,
                'uid' => $uid,
                'depth' => $depth,
                'indent' => $depth * 24,
                'selectable' => true,
                'label' => sprintf('(%d) %s', $uid, $text !== '' ? $text : $table),
            ];
            foreach ($childrenOf[$uid] ?? [] as $childUid) {
                $walk($childUid, $depth + 1);
            }
        };
        foreach (array_keys($parentOf) as $uid) {
            if ($parentOf[$uid] === 0) {
                $walk($uid, 0);
            }
        }
        return $tree;
    }

    /**
     * Split content uids into container-depth levels: a record is placed after
     * its container parent. Records whose parent is not part of the set
     * (standalone, or an already-translated container) land in the first level.
     *
     * @param list<int> $todo
     * @param array<int, int> $parentMap uid => tx_container_parent
     * @return list<list<int>>
     */
    private function orderByContainerLevels(array $todo, array $parentMap): array
    {
        $remaining = array_fill_keys($todo, true);
        $levels = [];
        $guard = 0;
        while ($remaining !== [] && $guard++ < 100) {
            $level = [];
            foreach (array_keys($remaining) as $uid) {
                $parent = $parentMap[$uid] ?? 0;
                if ($parent === 0 || !isset($remaining[$parent])) {
                    $level[] = $uid;
                }
            }
            if ($level === []) {
                // Unexpected cycle — flush everything remaining as one level.
                $level = array_keys($remaining);
            }
            foreach ($level as $uid) {
                unset($remaining[$uid]);
            }
            $levels[] = $level;
        }
        return $levels;
    }

    /**
     * Ensure the page has a translation in the target language: create it via
     * DataHandler localize if missing, translate the title, generate a fitting
     * slug, and create it hidden (hidden = 1) for editorial review. Idempotent —
     * an existing translation's hidden state is left untouched; only a still
     * "[Translate to …]"-prefixed title is repaired.
     *
     * @return string what happened, for the run report: created|updated|unchanged
     */
    private function ensurePageTranslation(
        int $pageId,
        int $targetLanguageId,
        ResolvedProfile $profile,
        string $pageContext = '',
        bool $publishPage = false,
    ): string {
        $uid = $this->pageTranslationUid($pageId, $targetLanguageId);
        $justCreated = false;
        if ($uid === 0) {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], ['pages' => [$pageId => ['localize' => $targetLanguageId]]]);
            $dataHandler->process_cmdmap();
            $uid = (int)($dataHandler->copyMappingArray['pages'][$pageId] ?? 0);
            if ($uid === 0) {
                return self::PAGE_UNCHANGED;
            }
            $justCreated = true;
        }

        $record = $this->row('pages', $uid);
        if ($record === null) {
            return self::PAGE_UNCHANGED;
        }

        $update = [];
        // A newly created translation starts hidden for review unless the run
        // asked for it to go live; never change the hidden state of an already
        // existing (possibly published) translation.
        if ($justCreated) {
            $update['hidden'] = $publishPage ? 0 : 1;
        }
        // (Re)translate the title when the page was just created or still carries
        // the "[Translate to …]" placeholder; otherwise leave the editor's title.
        // Also retranslate when a SEO meta field that HAS a source value is still
        // empty or prefixed in the translation. Fields the default page leaves
        // empty are skipped — otherwise every run would report work it can never
        // finish, and pay for a title translation each time.
        $default = $this->row('pages', $pageId) ?? [];
        $title = (string)($record['title'] ?? '');
        $sourceTitle = (string)($default['title'] ?? '');
        $metaNeedsTranslation = (bool)array_filter(
            self::PAGE_META_FIELDS,
            static fn (string $f): bool => trim((string)($default[$f] ?? '')) !== ''
                && (trim((string)($record[$f] ?? '')) === ''
                    || str_contains((string)($record[$f] ?? ''), '[Translate to')),
        );
        // A translated title identical to the source is NOT a reason to redo it —
        // brand names and short labels ("KI", "Team") legitimately stay the same,
        // and re-translating them on every run would never end.
        $titleNeedsTranslation = $justCreated || str_contains($title, '[Translate to');
        $retranslate = $titleNeedsTranslation || $metaNeedsTranslation;
        if ($titleNeedsTranslation) {
            $targetLanguage = $this->resolveTargetLanguage($pageId, $targetLanguageId);
            $title = $this->translatePlain($profile, $sourceTitle, $targetLanguageId, $targetLanguage, $pageContext);
            // pages.title is varchar(255); a rejected write would abort the whole
            // run, so an over-long translation loses to the source title.
            $titleMax = (int)($GLOBALS['TCA']['pages']['columns']['title']['config']['max'] ?? 0);
            if ($titleMax > 0 && mb_strlen($title) > $titleMax) {
                $this->context->addError(Messages::tr('field.tooLong', 'pages.title', (string)mb_strlen($title), (string)$titleMax));
                $title = $sourceTitle;
            }
            $record['title'] = $title;
            $update['title'] = $title;
            $update['slug'] = $this->generateSlug('pages', 'slug', $record);
            $update['tstamp'] = time();
        }

        if ($update !== []) {
            $this->connectionPool->getConnectionForTable('pages')->update('pages', $update, ['uid' => $uid]);
        }

        // Page media the source gained after the translation was created —
        // independent of the text state, so a pure image drift heals without
        // any LLM call.
        $mediaSynced = $this->syncMissingFileReferences('pages', [$uid], $targetLanguageId);

        if ($retranslate) {
            // Strip the "[Translate to …]" prefix that DataHandler adds to
            // prefixLangTitle fields (seo_title, description, …); otherwise the
            // ColumnTranslator skip-guard (translation ≠ source) treats them as
            // already-translated and leaves the untranslated German prefix in place.
            $this->stripTranslatePrefix('pages', [$uid], $pageId, $targetLanguageId);
            // Standard SEO/meta columns + any profile-configured page columns
            // (text, RTE or FlexForm, e.g. tx_dreistein_chat_flex).
            $this->columnTranslator->translateColumns(
                'pages',
                $uid,
                array_merge(self::PAGE_META_FIELDS, $profile->additionalColumnsFor('pages')),
                $profile,
                $this->resolveTargetLanguage($pageId, $targetLanguageId),
                $this->glossaryProvider->glossaryBlock($profile->profileUid, $targetLanguageId),
                $pageContext,
            );
        }

        return match (true) {
            $justCreated => self::PAGE_CREATED,
            $retranslate, $mediaSynced => self::PAGE_UPDATED,
            default => self::PAGE_UNCHANGED,
        };
    }

    private function pageTranslationUid(int $pageId, int $targetLanguageId): int
    {
        $ctrl = $GLOBALS['TCA']['pages']['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? 'sys_language_uid');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? 'l10n_parent');
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->eq($parentField, $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
                $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid !== false ? (int)$uid : 0;
    }

    /**
     * Map of default-language source uid => its translation uid for the given
     * table on a page/folder in the target language.
     *
     * @return array<int, int>
     */
    private function tableTranslationsBySource(string $table, int $pageId, int $targetLanguageId): array
    {
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? 'sys_language_uid');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? 'l10n_parent');
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $constraints = [
            $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
            $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, ParameterType::INTEGER)),
            $qb->expr()->gt($parentField, 0),
        ];
        if (($ctrl['delete'] ?? '') !== '') {
            $constraints[] = $qb->expr()->eq((string)$ctrl['delete'], 0);
        }
        $rows = $qb->select('uid', $parentField)
            ->from($table)
            ->where(...$constraints)
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row[$parentField]] = (int)$row['uid'];
        }
        return $map;
    }

    /**
     * Translate the inline (IRRE/ContentBlocks collection) children of the given
     * tt_content translation records. Run over ALL newly created translations of
     * a page so nested elements are reached too — e.g. a faq element sitting in a
     * b13 container, whose faq_items the localize hook never touches.
     *
     * @param list<int> $translationUids
     */
    private function runInlineOn(string $table, array $translationUids, ResolvedProfile $profile, int $pageId, int $targetLanguageId, string $pageContext, bool $force = false): void
    {
        if ($translationUids === []) {
            return;
        }
        // Remove the "[Translate to …]" prefix DataHandler adds to prefix-behaviour
        // fields when TCEMAIN.translateToMessage is configured (e.g. on a folder).
        $this->stripTranslatePrefix($table, $translationUids, $pageId, $targetLanguageId);
        // Catch up on images/files the source gained after the translation was
        // created — cheap no-op when nothing is missing.
        $this->syncMissingFileReferences($table, $translationUids, $targetLanguageId);

        $targetLanguage = $this->resolveTargetLanguage($pageId, $targetLanguageId);
        $glossaryBlock = $this->glossaryProvider->glossaryBlock($profile->profileUid, $targetLanguageId);
        // Every auto-translatable column (text/RTE; safe input) plus the
        // profile-configured extra columns (which may also be FlexForm).
        $columns = array_values(array_unique(array_merge(
            $this->fieldPolicy->autoTranslatableColumns($table),
            $profile->additionalColumnsFor($table),
        )));
        foreach ($translationUids as $uid) {
            $this->inlineTranslator->translateChildren(
                $table,
                (int)$uid,
                $profile,
                $targetLanguage,
                $glossaryBlock,
                $pageContext,
                $targetLanguageId,
            );
            $this->columnTranslator->translateColumns($table, (int)$uid, $columns, $profile, $targetLanguage, $glossaryBlock, $pageContext, $force);
            // After the columns, never before: the slug is built from them.
            $this->regenerateSlugs($table, (int)$uid, $force);
        }

        // Whether a translation is hidden is the source record's decision, not
        // ours — DataHandler would otherwise hide every one of them (hideAtCopy).
        // The page record is the exception: it has its own "publish" checkbox,
        // see ensurePageTranslation().
        $this->sourceVisibility->mirrorAll($table, array_map('intval', $translationUids));
    }

    /**
     * Localize file references the source record gained AFTER its translation
     * was created. DataHandler copies them on the initial localize, but never
     * afterwards — an image attached to the source later is silently missing
     * from every existing translation. Uses the official
     * inlineLocalizeSynchronize command, which clones only children without a
     * localization yet and keeps field counters and the refindex right.
     *
     * @param list<int> $translationUids
     * @return bool whether anything was synchronized
     */
    private function syncMissingFileReferences(string $table, array $translationUids, int $targetLanguageId): bool
    {
        $fileFields = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column => $definition) {
            if ((string)($definition['config']['type'] ?? '') === 'file') {
                $fileFields[] = (string)$column;
            }
        }
        $transOrig = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        if ($fileFields === [] || $transOrig === '') {
            return false;
        }
        $synced = false;
        foreach ($translationUids as $uid) {
            $row = $this->row($table, (int)$uid);
            $sourceUid = (int)($row[$transOrig] ?? 0);
            if ($sourceUid <= 0) {
                continue;
            }
            foreach ($fileFields as $field) {
                if (!$this->hasUnlocalizedFileReferences($table, $sourceUid, (int)$uid, $field)) {
                    continue;
                }
                $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
                $dataHandler->start([], [$table => [(int)$uid => [
                    // Without 'language' the core silently returns ($language <= 0).
                    'inlineLocalizeSynchronize' => ['field' => $field, 'action' => 'localize', 'language' => $targetLanguageId],
                ]]]);
                $dataHandler->process_cmdmap();
                foreach ($dataHandler->errorLog as $error) {
                    $this->context->addError(sprintf('%s:%d %s: %s', $table, $uid, $field, (string)$error));
                }
                $synced = true;
            }
        }
        return $synced;
    }

    /**
     * Whether the source record has file references in $field that no
     * reference of the translation points back to (via l10n_parent).
     */
    private function hasUnlocalizedFileReferences(string $table, int $sourceUid, int $translationUid, string $field): bool
    {
        $refs = function (int $foreignUid, string $select) use ($table, $field): array {
            $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
            $qb->getRestrictions()->removeAll();
            return $qb->select($select)
                ->from('sys_file_reference')
                ->where(
                    $qb->expr()->eq('tablenames', $qb->createNamedParameter($table)),
                    $qb->expr()->eq('fieldname', $qb->createNamedParameter($field)),
                    $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($foreignUid, ParameterType::INTEGER)),
                    $qb->expr()->eq('deleted', 0),
                )
                ->executeQuery()
                ->fetchFirstColumn();
        };
        $sourceRefs = array_map('intval', $refs($sourceUid, 'uid'));
        if ($sourceRefs === []) {
            return false;
        }
        $localizedParents = array_map('intval', $refs($translationUid, 'l10n_parent'));
        return array_diff($sourceRefs, $localizedParents) !== [];
    }

    /**
     * Reset fields still carrying the configured "[Translate to <lang>:] "
     * prefix to their current source value (only fields with the exact
     * configured prefix, so it is safe) — they are untranslated by definition,
     * and equal-to-source is what makes the ColumnTranslator pick them up.
     *
     * @param list<int> $translationUids
     */
    private function stripTranslatePrefix(string $table, array $translationUids, int $pageId, int $targetLanguageId): void
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId)['TCEMAIN.'] ?? [];
        $message = (string)($tsConfig['translateToMessage'] ?? '');
        if (trim($message) === '') {
            return;
        }
        // DataHandler builds the prefix from the site language TITLE (free text,
        // e.g. "Englisch"), while the prompts use the locale-derived English
        // name ("English"). Match both — a miss here leaves the prefix in place
        // and the ColumnTranslator skip-guard then mistakes "[Translate to …] "
        // + source text for an existing translation.
        $names = array_unique(array_filter([
            $this->siteLanguageTitle($pageId, $targetLanguageId),
            $this->resolveTargetLanguage($pageId, $targetLanguageId),
        ]));
        $prefixes = [];
        foreach ($names as $name) {
            $prefixes[] = '[' . trim(@sprintf($message, $name)) . '] ';
        }
        $columns = array_keys($GLOBALS['TCA'][$table]['columns'] ?? []);
        $connection = $this->connectionPool->getConnectionForTable($table);
        $transOrig = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');

        foreach ($translationUids as $uid) {
            $row = $this->row($table, (int)$uid);
            if ($row === null) {
                continue;
            }
            $sourceUid = $transOrig !== '' ? (int)($row[$transOrig] ?? 0) : 0;
            $source = null;
            $update = [];
            foreach ($columns as $column) {
                $value = (string)($row[$column] ?? '');
                if ($value === '') {
                    continue;
                }
                foreach ($prefixes as $prefix) {
                    if (!str_contains($value, $prefix)) {
                        continue;
                    }
                    // A prefixed field is DataHandler's untranslated copy of the
                    // source — reset it to the CURRENT source value instead of
                    // just stripping: the stripped text may be an outdated source
                    // state, which the ColumnTranslator skip-guard (translation ≠
                    // source) would mistake for a finished translation.
                    $source ??= $sourceUid > 0 ? ($this->row($table, $sourceUid) ?? []) : [];
                    if (array_key_exists($column, $source)) {
                        $update[$column] = (string)($source[$column] ?? '');
                    } elseif (str_starts_with($value, $prefix)) {
                        $update[$column] = substr($value, strlen($prefix));
                    } else {
                        // HTML: prefix sits right after the first opening tag.
                        $update[$column] = preg_replace('/^(\s*<[^>]+>)' . preg_quote($prefix, '/') . '/', '$1', $value, 1) ?? $value;
                    }
                    break;
                }
            }
            if ($update !== []) {
                $connection->update($table, $update, ['uid' => (int)$uid]);
            }
        }
    }

    /**
     * Title of the target site language exactly as DataHandler saw it when it
     * built the "[Translate to …:] " prefix. Falls back to a cross-site lookup
     * for records in folders outside any site rootline.
     */
    private function siteLanguageTitle(int $pageId, int $languageId): string
    {
        try {
            return trim($this->siteFinder->getSiteByPageId($pageId)->getLanguageById($languageId)->getTitle());
        } catch (\Throwable) {
            // fall through to the cross-site lookup
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                return trim($site->getLanguageById($languageId)->getTitle());
            } catch (\Throwable) {
                continue;
            }
        }
        return '';
    }

    private function translatePlain(ResolvedProfile $profile, string $text, int $targetLanguageId, string $targetLanguage, string $pageContext = ''): string
    {
        if (trim($text) === '') {
            return $text;
        }
        $glossaryBlock = $this->glossaryProvider->glossaryBlock($profile->profileUid, $targetLanguageId);
        return $this->translationService->translateField($text, $profile, $targetLanguage, $glossaryBlock, $pageContext, false);
    }

    /**
     * Assemble the page's default-language content as plain text, so the LLM can
     * "read the whole page" once (cached) and translate each element coherently.
     * HTML is stripped and the result is capped to keep the cached prefix sane.
     */
    private function buildPageContext(int $pageId): string
    {
        if ($pageId <= 0) {
            return '';
        }
        $languageField = (string)($GLOBALS['TCA']['tt_content']['ctrl']['languageField'] ?? 'sys_language_uid');
        $parts = [];
        $page = $this->row('pages', $pageId);
        if ($page !== null && trim((string)($page['title'] ?? '')) !== '') {
            $parts[] = '# ' . trim((string)$page['title']);
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, ParameterType::INTEGER)),
                $qb->expr()->eq($languageField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $chunk = trim(implode("\n", array_filter([
                trim((string)($row['header'] ?? '')),
                trim((string)($row['subheader'] ?? '')),
                trim(strip_tags((string)($row['bodytext'] ?? ''))),
            ])));
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        $context = trim(implode("\n\n", $parts));
        // Keep the cached prefix bounded; very long pages are truncated.
        return mb_strlen($context) > 8000 ? mb_substr($context, 0, 8000) . ' …' : $context;
    }

    /**
     * Build a slug from a record whose generator fields carry the translated
     * values already.
     *
     * @param array<string, mixed> $record full record
     */
    private function generateSlug(string $table, string $field, array $record): string
    {
        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
        $slugHelper = GeneralUtility::makeInstance(SlugHelper::class, $table, $field, $fieldConfig);
        $pid = (int)($record['pid'] ?? 0);
        $slug = $slugHelper->generate($record, $pid);
        $state = RecordStateFactory::forName($table)->fromArray($record, $pid, (int)($record['uid'] ?? 0));

        // The scope a slug has to be unique in is part of its TCA eval:
        // pages are unique per site, news and the rest per table.
        $evals = GeneralUtility::trimExplode(',', (string)($fieldConfig['eval'] ?? ''), true);
        if (in_array('uniqueInSite', $evals, true)) {
            return $slugHelper->isUniqueInSite($slug, $state) ? $slug : $slugHelper->buildSlugForUniqueInSite($slug, $state);
        }
        if (in_array('uniqueInPid', $evals, true)) {
            return $slugHelper->isUniqueInPid($slug, $state) ? $slug : $slugHelper->buildSlugForUniqueInPid($slug, $state);
        }
        if (in_array('unique', $evals, true)) {
            return $slugHelper->isUniqueInTable($slug, $state) ? $slug : $slugHelper->buildSlugForUniqueInTable($slug, $state);
        }

        return $slug;
    }

    /**
     * Rebuild the slug fields of a translated record.
     *
     * A slug is not prose. Handing "sobocan-doo-kroatien-effiziente-…" to the
     * LLM would return something that still has to be transliterated, lowered,
     * stripped of umlauts and made unique — all of which SlugHelper does, and
     * it reads exactly the fields the slug is generated from, which this run
     * has just translated. So the slug is regenerated, not translated.
     *
     * A slug that already differs from the source is left alone: that is either
     * an editor's own wording or the result of an earlier run, and overwriting
     * it would break links that are already out there. A forced re-translation
     * rebuilds it regardless.
     */
    private function regenerateSlugs(string $table, int $uid, bool $force): void
    {
        $slugColumns = [];
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column => $definition) {
            if ((string)($definition['config']['type'] ?? '') === 'slug'
                && (string)($definition['l10n_mode'] ?? '') !== 'exclude'
            ) {
                $slugColumns[] = (string)$column;
            }
        }
        if ($slugColumns === []) {
            return;
        }

        $translation = $this->row($table, $uid);
        if ($translation === null) {
            return;
        }
        $parentField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        $sourceUid = $parentField !== '' ? (int)($translation[$parentField] ?? 0) : 0;
        $source = $sourceUid > 0 ? $this->row($table, $sourceUid) : null;

        $update = [];
        foreach ($slugColumns as $column) {
            $current = (string)($translation[$column] ?? '');
            // Still byte-identical to the source means DataHandler copied it
            // along and nobody has touched it since.
            $copiedFromSource = $source !== null && $current === (string)($source[$column] ?? '');
            if (!$force && $current !== '' && !$copiedFromSource) {
                continue;
            }
            $slug = $this->generateSlug($table, $column, $translation);
            if ($slug !== '' && $slug !== $current) {
                $update[$column] = $slug;
            }
        }

        if ($update !== []) {
            $this->connectionPool->getConnectionForTable($table)->update($table, $update, ['uid' => $uid]);
        }
    }

    /** @return array<string, mixed>|null */
    private function row(string $table, int $uid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, list<int>> $records table => [uids]
     */
    private function run(
        ResolvedProfile $profile,
        int $pageId,
        int $targetLanguageId,
        array $records,
        string $pageContext = ''
    ): TranslationRunResult {
        $targetLanguage = $this->resolveTargetLanguage($pageId, $targetLanguageId);

        $cmdmap = [];
        $requested = 0;
        foreach ($records as $table => $uids) {
            foreach ($uids as $uid) {
                $cmdmap[$table][$uid] = ['localize' => $targetLanguageId];
                $requested++;
            }
        }

        // Snapshot existing translations so we can report only the records this
        // run actually created (copyMappingArray is unreliable for multi-record
        // localize commands).
        $before = [];
        foreach ($records as $table => $uids) {
            $before[$table] = $this->existingTranslations($table, $uids, $targetLanguageId);
        }

        $glossaryBlock = $this->glossaryProvider->glossaryBlock($profile->profileUid, $targetLanguageId);
        $this->context->arm($profile, $targetLanguage, $glossaryBlock, $pageContext);
        try {
            $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $dataHandler->start([], $cmdmap);
            $dataHandler->process_cmdmap();
            $errors = array_map('strval', $dataHandler->errorLog);
        } finally {
            $this->context->disarm();
        }

        // Inline children (IRRE/ContentBlocks collections) are translated in a
        // dedicated pass by the caller, over ALL newly created translations on
        // the page — including b13 container children that this run() does not
        // see directly (e.g. a faq element nested in a section).
        $createdUids = [];
        foreach ($records as $table => $uids) {
            $after = $this->existingTranslations($table, $uids, $targetLanguageId);
            $createdUids = array_merge($createdUids, array_values(array_diff($after, $before[$table])));
        }

        return new TranslationRunResult(
            ok: $errors === [] || $createdUids !== [],
            requested: $requested,
            createdUids: $createdUids,
            errors: $errors,
        );
    }

    /**
     * Translation record uids whose source (transOrigPointer) is in $sourceUids
     * for the given target language. Used for before/after diffing.
     *
     * @param list<int> $sourceUids
     * @return list<int>
     */
    private function existingTranslations(string $table, array $sourceUids, int $targetLanguageId): array
    {
        if ($sourceUids === []) {
            return [];
        }
        $ctrl = $GLOBALS['TCA'][$table]['ctrl'] ?? [];
        $languageField = (string)($ctrl['languageField'] ?? '');
        $parentField = (string)($ctrl['transOrigPointerField'] ?? '');
        if ($languageField === '' || $parentField === '') {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid')
            ->from($table)
            ->where(
                $qb->expr()->in($parentField, $qb->createNamedParameter($sourceUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq($languageField, $qb->createNamedParameter($targetLanguageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeQuery()
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }

    public function resolveTargetLanguage(int $pageId, int $targetLanguageId): string
    {
        $name = $this->languageNames->targetName($pageId, $targetLanguageId);
        return $name !== '' ? $name : sprintf('language #%d', $targetLanguageId);
    }
}
