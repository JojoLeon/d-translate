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

use Dreistein\DTranslate\Configuration\ExtensionSettings;
use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Job\JobRunner;
use Dreistein\DTranslate\Job\JobStore;
use Dreistein\DTranslate\Job\TranslationJob;
use Dreistein\DTranslate\Exception\TranslationException;
use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Service\ProfileLlmResolver;
use Dreistein\DTranslate\Service\ProfileStore;
use Dreistein\DTranslate\Service\TranslationOrchestrator;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Backend module "Translation". Editor-facing surface for translation runs:
 * pick a profile, target languages and records, then let the queue do the work.
 * Profiles and LLM configurations themselves are plain records on the root
 * level and are edited there — this module never touches provider credentials.
 */
final class TranslationModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ProfileStore $profileStore,
        private readonly ProfileLlmResolver $resolver,
        private readonly JobStore $jobStore,
        private readonly JobRunner $jobRunner,
        private readonly TranslationOrchestrator $orchestrator,
        private readonly SiteFinder $siteFinder,
        private readonly FlashMessageService $flashMessageService,
        private readonly UriBuilder $uriBuilder,
        private readonly LoggerInterface $logger,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /** Shorthand for the message catalogue. */
    private function tr(string $key, int|float|string ...$args): string
    {
        return Messages::tr($key, ...$args);
    }

    public function overview(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle('Translation');

        // Pre-fill the page id with the page currently selected in the page tree
        // (the backend passes it as the `id` query parameter).
        $currentPageId = (int)($request->getQueryParams()['id'] ?? 0);

        $view->assignMultiple([
            'currentPageId' => $currentPageId,
            'publishPagesDefault' => $this->settings->publishPages(),
            'backgroundDefault' => $this->settings->backgroundDefault(),
            'confirmAboveUnits' => $this->settings->confirmAboveUnits(),
            'assetVersion' => $this->assetVersion(),
            'jobs' => $this->recentJobs(),
            'startUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate.start'),
            'stepUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate.step'),
            'statusUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate.status'),
            'cancelUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate.cancel'),
            'pageLabel' => $this->pageLabel($currentPageId),
            'recordGroups' => $this->orchestrator->recordGroupsForPage($currentPageId),
            // Profiles and LLM configurations are edited as records on the root
            // level; the module only picks which profile a run uses.
            'profiles' => $this->profileStore->listSelectable(),
            'activeProfile' => $this->resolver->defaultProfileUid($currentPageId),
            'languages' => $this->languageOptions($currentPageId),
            'translateUrl' => (string)$this->uriBuilder->buildUriFromRoute('dtranslate.translate'),
        ]);

        return $view->renderResponse('Backend/Overview');
    }

    /**
     * Cache-busting stamp for the module's own CSS and JavaScript.
     *
     * In Composer mode the asset path resolves to /_assets/<hash>/… and that
     * hash is derived from the extension name, not from the file contents — so
     * the URL never changes when a file is edited, no revalidation header is
     * sent either, and an editor's browser keeps running yesterday's script
     * until it decides to look again. Appending the newest mtime gives every
     * change its own URL.
     */
    private function assetVersion(): string
    {
        $newest = 0;
        foreach ([
            'Css/backend-module.css',
            'JavaScript/translate-form.js',
            'JavaScript/about-modal.js',
        ] as $file) {
            $path = GeneralUtility::getFileAbsFileName('EXT:d_translate/Resources/Public/' . $file);
            $newest = max($newest, (int)@filemtime($path));
        }

        return (string)$newest;
    }

    /**
     * Non-JS submit: the run is queued and picked up by the worker. A synchronous
     * run in this request would be at the mercy of the PHP process limit, which
     * is exactly what breaks on large selections.
     */
    public function translate(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        $pageId = (int)($params['pageId'] ?? 0);
        $languageIds = $this->languageIds($params);
        $selectedByTable = $this->selectedRecords($params);

        if ($pageId <= 0 || $languageIds === []) {
            $this->flash($this->tr('run.selectPageAndLanguage'), ContextualFeedbackSeverity::WARNING);
            return $this->back($pageId);
        }

        $blocking = $this->jobStore->findOpenForPage($pageId, $languageIds);
        if ($blocking !== null) {
            $this->flash($this->tr('run.alreadyRunning', $blocking->uid()), ContextualFeedbackSeverity::WARNING);
            return $this->back($pageId);
        }

        $job = $this->jobStore->create(
            $pageId,
            $languageIds,
            $selectedByTable,
            (bool)($params['publishPage'] ?? false),
            true,
            (bool)($params['skipTranslated'] ?? false),
            (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            (int)($params['profile'] ?? 0),
        );
        $this->flash($this->tr('run.queued', $job->uid(), $job->total()), ContextualFeedbackSeverity::OK);
        return $this->back($pageId);
    }

    /**
     * Create the job for a run. With "background" the worker takes over; without
     * it the browser drives the items one by one through {@see step()}.
     */
    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        $pageId = (int)($params['pageId'] ?? 0);
        $languageIds = $this->languageIds($params);
        $selectedByTable = $this->selectedRecords($params);
        $background = (bool)($params['background'] ?? false);

        if ($pageId <= 0 || $languageIds === []) {
            return new JsonResponse(['ok' => false, 'message' => $this->tr('run.invalidParameters')]);
        }

        $blocking = $this->jobStore->findOpenForPage($pageId, $languageIds);
        if ($blocking !== null) {
            return new JsonResponse([
                'ok' => false,
                'message' => $this->tr('run.alreadyRunning', $blocking->uid()),
            ]);
        }

        $job = $this->jobStore->create(
            $pageId,
            $languageIds,
            $selectedByTable,
            (bool)($params['publishPage'] ?? false),
            $background,
            (bool)($params['skipTranslated'] ?? false),
            (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            (int)($params['profile'] ?? 0),
        );

        return new JsonResponse([
            'ok' => true,
            'job' => $job->uid(),
            'total' => $job->total(),
            'background' => $background,
            'message' => $background ? $this->tr('run.queued', $job->uid(), $job->total()) : '',
        ]);
    }

    /**
     * Translate the next item of a job. One record in one language per request —
     * short enough to stay well inside any web server or FPM limit.
     */
    public function step(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        $job = $this->jobStore->find((int)($params['job'] ?? 0));
        if ($job === null) {
            return new JsonResponse(['ok' => false, 'finished' => true, 'message' => $this->tr('run.invalidParameters')]);
        }
        if (!$job->isOpen()) {
            return $this->stepResponse($job, true, null, '');
        }

        $item = $this->jobStore->claimNextItem($job->uid());
        if ($item === null) {
            $job = $this->jobStore->finishIfComplete($job->uid()) ?? $job;
            return $this->stepResponse($job, true, null, '');
        }

        $outcome = $this->jobRunner->process($job, $item);
        $this->jobStore->completeItem($item, $outcome['ok'], $outcome['message']);
        if ($outcome['fatal']) {
            $this->jobStore->setState($job->uid(), TranslationJob::STATE_FAILED, $outcome['message']);
        }
        $job = $this->jobStore->finishIfComplete($job->uid()) ?? $job;

        $label = $this->languageLabel($job->pageId(), $item->languageId());
        $subject = $item->isPageMeta() ? $this->tr('js.pageMeta') : $item->label();
        return $this->stepResponse(
            $job,
            !$job->isOpen(),
            $outcome,
            sprintf('%s · %s: %s%s', $label, $subject, $outcome['ok'] ? '' : '⚠ ', $outcome['message']),
        );
    }

    /** Progress of a job, for the background poll in the module. */
    public function status(ServerRequestInterface $request): ResponseInterface
    {
        $job = $this->jobStore->find((int)($request->getQueryParams()['job'] ?? 0));
        if ($job === null) {
            return new JsonResponse(['ok' => false]);
        }
        return $this->stepResponse($job, !$job->isOpen(), null, '');
    }

    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $jobId = (int)((array)$request->getParsedBody())['job'] ?? 0;
        $jobId = $jobId ?: (int)($request->getQueryParams()['job'] ?? 0);
        $job = $this->jobStore->find($jobId);
        if ($job !== null && $job->isOpen()) {
            $this->jobStore->cancel($jobId);
            $this->flash($this->tr('run.cancelled', $jobId), ContextualFeedbackSeverity::INFO);
        }
        return $this->back((int)($request->getQueryParams()['id'] ?? ($job?->pageId() ?? 0)));
    }

    /**
     * @param array{ok: bool, fatal: bool, message: string}|null $outcome
     */
    private function stepResponse(TranslationJob $job, bool $finished, ?array $outcome, string $message): ResponseInterface
    {
        return new JsonResponse([
            'ok' => $outcome === null ? true : $outcome['ok'],
            'fatal' => $outcome['fatal'] ?? false,
            'finished' => $finished,
            'state' => $job->state(),
            'done' => $job->done(),
            'failed' => $job->failed(),
            'total' => $job->total(),
            'percent' => $job->percent(),
            'calls' => $job->calls(),
            'tokens' => $job->tokensIn() + $job->tokensOut(),
            'message' => $message,
        ]);
    }

    /**
     * Recent runs for the module list — state, progress and what they consumed.
     *
     * @return list<array<string, mixed>>
     */
    private function recentJobs(): array
    {
        $rows = [];
        foreach ($this->jobStore->listRecent(5) as $job) {
            $rows[] = [
                'uid' => $job->uid(),
                'pageId' => $job->pageId(),
                'state' => $job->state(),
                'open' => $job->isOpen(),
                'background' => $job->isBackground(),
                'done' => $job->done(),
                'failed' => $job->failed(),
                'total' => $job->total(),
                'percent' => $job->percent(),
                'calls' => $job->calls(),
                'tokens' => $job->tokensIn() + $job->tokensOut(),
                'crdate' => $job->crdate(),
                'languages' => implode(', ', array_map(
                    fn (int $id): string => $this->languageLabel($job->pageId(), $id),
                    $job->languages(),
                )),
                'message' => $job->message(),
            ];
        }
        return $rows;
    }

    /** @param array<string, mixed> $params @return list<int> */
    private function languageIds(array $params): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array)($params['language'] ?? [])))));
    }

    /** @param array<string, mixed> $params @return array<string, list<int>> */
    private function selectedRecords(array $params): array
    {
        $selected = [];
        foreach ((array)($params['records'] ?? []) as $value) {
            $parts = explode(':', (string)$value, 2);
            if (count($parts) === 2 && $parts[0] !== '' && ctype_digit($parts[1])) {
                $selected[$parts[0]][] = (int)$parts[1];
            }
        }
        return $selected;
    }

    /**
     * One sentence for the editor. LLM failures already carry a diagnosed
     * message ({@see LlmErrorFormatter}); anything else stays generic and
     * points at the log, where the stack trace lives.
     */
    private function describe(\Throwable $e): string
    {
        if ($e instanceof LlmRequestException) {
            return $e->userMessage;
        }
        if ($e instanceof TranslationException) {
            return $e->getMessage();
        }
        return $this->tr('run.genericError', (new \ReflectionClass($e))->getShortName(), $e->getMessage());
    }

    /** A fatal cause repeats on every remaining chunk — the frontend stops the run. */
    private function isFatal(\Throwable $e): bool
    {
        return !$e instanceof LlmRequestException || $e->fatal;
    }

    private function languageLabel(int $pageId, int $languageId): string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            return $site->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
        }
        foreach ($this->siteFinder->getAllSites() as $site) {
            try {
                return $site->getLanguageById($languageId)->getTitle();
            } catch (\Throwable) {
            }
        }
        return sprintf('#%d', $languageId);
    }

    /**
     * Target languages offered for a page: those of ITS site, minus the default
     * language. Listing every language of every site produced dozens of
     * duplicate entries on multi-site installations — and offering a language
     * the page's site does not have is meaningless anyway.
     *
     * Records in a folder outside any site rootline keep the full list, then
     * deduplicated by language id.
     *
     * @return list<array{site: string, languageId: int, title: string}>
     */
    /** "(1859) Kantenanleimmaschinen" — same shape as the record labels below it. */
    private function pageLabel(int $pageId): string
    {
        $row = BackendUtility::getRecord('pages', $pageId, 'uid,title');
        $title = trim((string)($row['title'] ?? ''));
        return sprintf('(%d) %s', $pageId, $title !== '' ? $title : 'pages');
    }

    private function languageOptions(int $pageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $defaultId = $site->getDefaultLanguage()->getLanguageId();
            $options = [];
            foreach ($site->getAllLanguages() as $language) {
                if ($language->getLanguageId() === $defaultId) {
                    continue;
                }
                $options[] = [
                    'site' => $site->getIdentifier(),
                    'languageId' => $language->getLanguageId(),
                    'title' => $language->getTitle(),
                ];
            }
            return $options;
        } catch (\Throwable) {
            // No site for this page — fall through to the cross-site list.
        }

        $options = [];
        $seen = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $defaultId = $site->getDefaultLanguage()->getLanguageId();
            foreach ($site->getAllLanguages() as $language) {
                $id = $language->getLanguageId();
                if ($id === $defaultId || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $options[] = [
                    'site' => $site->getIdentifier(),
                    'languageId' => $id,
                    'title' => $language->getTitle(),
                ];
            }
        }
        return $options;
    }



    private function back(int $pageId = 0): RedirectResponse
    {
        $params = $pageId > 0 ? ['id' => $pageId] : [];
        return new RedirectResponse((string)$this->uriBuilder->buildUriFromRoute('dtranslate', $params));
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $message, '', $severity, true);
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue($flashMessage);
    }
}
