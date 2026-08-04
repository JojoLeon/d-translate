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

/**
 * Outcome of a translation run (single record or whole page). Carries enough
 * for both the backend module flash messages and the (later) scheduler log.
 */
final class TranslationRunResult
{
    /**
     * @param list<int>    $createdUids uids of the new localized records
     * @param list<string> $errors      DataHandler / resolution errors
     * @param string       $pageStatus  what happened to the page translation
     *                                  itself: created|updated|unchanged (empty
     *                                  when the run never touched a page)
     */
    public function __construct(
        public readonly bool $ok,
        public readonly int $requested = 0,
        public readonly int $retranslated = 0,
        public readonly array $createdUids = [],
        public readonly array $errors = [],
        public readonly string $message = '',
        public readonly string $pageStatus = '',
    ) {
    }

    /** The page translation counts as work done, next to the records. */
    public function touchedAnything(): bool
    {
        return $this->createdUids !== []
            || $this->retranslated > 0
            || in_array($this->pageStatus, ['created', 'updated'], true);
    }

    public static function failure(string $message): self
    {
        return new self(false, message: $message);
    }

    public function created(): int
    {
        return count($this->createdUids);
    }
}
