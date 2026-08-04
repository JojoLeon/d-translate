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

namespace Dreistein\DTranslate\Translation;

use Dreistein\DTranslate\Llm\ResolvedProfile;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Request-scoped state shared between the command-map hook (writer) and the
 * per-field translate hook (reader).
 *
 * Two jobs, both learned from the wv_deepltranslate analysis:
 *  1. Opt-in gate — the translate hook fires on EVERY localize in the system,
 *     so it must stay inert unless a d-translate trigger has explicitly armed
 *     it (with the resolved profile + target language).
 *  2. Table carrier — processTranslateTo_copyAction does not receive the table
 *     name; the command-map hook records it here for the field hook to read.
 *
 * SingletonInterface guarantees a single instance per request whether reached
 * via DI or GeneralUtility::makeInstance.
 */
final class ProcessingContext implements SingletonInterface
{
    private bool $armed = false;
    private ?ResolvedProfile $profile = null;
    private string $targetLanguage = '';
    private string $glossaryBlock = '';
    private string $pageContext = '';
    private ?string $currentTable = null;
    private ?int $currentUid = null;
    /** @var list<string> non-fatal field failures collected during a run */
    private array $errors = [];

    public function arm(ResolvedProfile $profile, string $targetLanguage, string $glossaryBlock = '', string $pageContext = ''): void
    {
        $this->armed = true;
        $this->profile = $profile;
        $this->targetLanguage = $targetLanguage;
        $this->glossaryBlock = $glossaryBlock;
        $this->pageContext = $pageContext;
    }

    public function disarm(): void
    {
        $this->armed = false;
        $this->profile = null;
        $this->glossaryBlock = '';
        $this->pageContext = '';
        $this->currentTable = null;
        $this->currentUid = null;
    }

    public function getGlossaryBlock(): string
    {
        return $this->glossaryBlock;
    }

    /** Whole-page source text, injected as cached context so each field is translated coherently. */
    public function getPageContext(): string
    {
        return $this->pageContext;
    }

    public function isArmed(): bool
    {
        return $this->armed;
    }

    public function getProfile(): ?ResolvedProfile
    {
        return $this->profile;
    }

    public function getTargetLanguage(): string
    {
        return $this->targetLanguage;
    }

    /** Recorded by the command-map hook for the (table-less) field hook. */
    public function setProcessing(string $table, int $uid): void
    {
        $this->currentTable = $table;
        $this->currentUid = $uid;
    }

    public function getTable(): ?string
    {
        return $this->currentTable;
    }

    public function getUid(): ?int
    {
        return $this->currentUid;
    }

    /**
     * Fields whose translation failed without aborting the run (rate limit,
     * provider hiccup, timeout). Collected here because the field-level hooks
     * have no way back to the module — without this the run reported success
     * while the records silently kept their source text.
     */
    public function addError(string $message): void
    {
        $message = trim($message);
        if ($message === '' || in_array($message, $this->errors, true)) {
            return;
        }
        // A run touches hundreds of fields; a handful of distinct messages is
        // all the module can show anyway.
        if (count($this->errors) < 10) {
            $this->errors[] = $message;
        }
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function clearErrors(): void
    {
        $this->errors = [];
    }
}
