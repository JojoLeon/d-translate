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
use Dreistein\DTranslate\Llm\LlmProtocol;
use Dreistein\DTranslate\Llm\ProviderDefaults;
use Dreistein\DTranslate\Llm\ResolvedLlm;
use Dreistein\DTranslate\Llm\ResolvedProfile;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Resolves the translation profile for a page: site → tx_dtranslate_profile →
 * tx_dtranslate_llm_config → {@see ResolvedProfile}. Falls back to the
 * extension configuration (Anthropic credentials) when no profile is defined
 * for the site, so the extension stays usable before any profile is set up.
 */
final class ProfileLlmResolver
{
    public function __construct(
        private readonly ExtensionSettings $settings,
        private readonly ProfileStore $profileStore,
        private readonly LlmConfigStore $llmConfigStore,
        private readonly SiteFinder $siteFinder,
        private readonly LanguageNameResolver $languageNames,
    ) {
    }

    /** The profile that applies to a page when the caller did not choose one. */
    public function defaultProfileUid(int $pid): int
    {
        try {
            $siteIdentifier = $this->siteFinder->getSiteByPageId($pid)->getIdentifier();
        } catch (SiteNotFoundException) {
            $siteIdentifier = '';
        }
        $row = $siteIdentifier !== '' ? $this->profileStore->findBySiteIdentifier($siteIdentifier) : null;
        $row ??= $this->profileStore->findDefault();
        return (int)($row['uid'] ?? 0);
    }

    public function resolveForPage(int $pid, ?int $profileUid = null): ResolvedProfile
    {
        $siteIdentifier = '';
        $defaultLanguageTitle = '';
        try {
            $site = $this->siteFinder->getSiteByPageId($pid);
            $siteIdentifier = $site->getIdentifier();
            $defaultLanguageTitle = $this->languageNames->nameOf($site->getDefaultLanguage());
        } catch (SiteNotFoundException) {
            // no site → fall back below
        }

        // An explicitly chosen profile always wins — the module lets the editor
        // pick one per run, and a silent fallback to "some other profile" would
        // translate with the wrong language, tone or model.
        $profileRow = $profileUid !== null && $profileUid > 0 ? $this->profileStore->find($profileUid) : null;
        if ($profileRow === null) {
            $profileRow = $siteIdentifier !== '' ? $this->profileStore->findBySiteIdentifier($siteIdentifier) : null;
        }
        // Records may live in a SysFolder outside any site rootline (e.g. a "Data"
        // or "News" folder), and a site may simply have no profile of its own —
        // both cases fall back to the profile marked as default (no site), never
        // to an arbitrary one.
        if ($profileRow === null) {
            $profileRow = $this->profileStore->findDefault();
        }
        if ($profileRow === null) {
            return $this->fallbackProfile($defaultLanguageTitle);
        }

        $llmRow = $this->llmConfigStore->find((int)($profileRow['llm_config'] ?? 0));
        if ($llmRow === null) {
            return $this->fallbackProfile($defaultLanguageTitle);
        }

        $sourceLanguage = trim((string)($profileRow['source_language'] ?? ''));
        if ($sourceLanguage === '') {
            $sourceLanguage = $defaultLanguageTitle !== '' ? $defaultLanguageTitle : $this->settings->sourceLanguage();
        }

        return new ResolvedProfile(
            llm: $this->buildLlm($llmRow),
            sourceLanguage: $sourceLanguage,
            formality: (string)($profileRow['formality'] ?? 'default'),
            styleHint: trim((string)($profileRow['style_hint'] ?? '')),
            translateTables: $this->parseTables((string)($profileRow['translate_tables'] ?? '')),
            profileUid: (int)($profileRow['uid'] ?? 0),
            additionalFields: $this->parseAdditionalFields((string)($profileRow['additional_fields'] ?? '')),
        );
    }

    private function fallbackProfile(string $defaultLanguageTitle): ResolvedProfile
    {
        $source = $defaultLanguageTitle !== '' ? $defaultLanguageTitle : $this->settings->sourceLanguage();
        return new ResolvedProfile(
            llm: new ResolvedLlm(
                type: 'anthropic',
                label: 'Anthropic (ext config fallback)',
                protocol: LlmProtocol::ANTHROPIC,
                endpoint: $this->settings->anthropicApiBase(),
                apiKey: $this->settings->anthropicApiKey(),
                chatModel: $this->settings->anthropicChatModel(),
            ),
            sourceLanguage: $source,
        );
    }

    /** @param array<string, mixed> $row */
    private function buildLlm(array $row): ResolvedLlm
    {
        $type = (string)($row['type'] ?? 'anthropic');
        $endpoint = trim((string)($row['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = ProviderDefaults::endpoint($type);
        }
        return new ResolvedLlm(
            type: $type,
            label: (string)($row['title'] ?? $type),
            protocol: $type === 'anthropic' ? LlmProtocol::ANTHROPIC : LlmProtocol::OPENAI,
            endpoint: $endpoint,
            apiKey: (string)($row['api_key'] ?? ''),
            chatModel: (string)($row['chat_model'] ?? ''),
        );
    }

    /**
     * Table allowlist: commas and line breaks both separate, mixed freely —
     * editors paste lists in either shape.
     *
     * @return list<string>
     */
    private function parseTables(string $raw): array
    {
        $tables = array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $raw) ?: []));
        return array_values(array_unique($tables));
    }

    /**
     * Parse the "additional_fields" textarea: "table.column" entries, separated
     * by line breaks or commas, mixed freely.
     *
     * @return list<array{table: string, column: string}>
     */
    private function parseAdditionalFields(string $raw): array
    {
        $fields = [];
        $seen = [];
        foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, '.')) {
                continue;
            }
            [$table, $column] = explode('.', $line, 2);
            $table = trim($table);
            $column = trim($column);
            if ($table !== '' && $column !== '' && !isset($seen[$table . '.' . $column])) {
                $seen[$table . '.' . $column] = true;
                $fields[] = ['table' => $table, 'column' => $column];
            }
        }
        return $fields;
    }
}
