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

namespace Dreistein\DTranslate\Job;

/**
 * One unit of translation work: a single record in a single target language —
 * or, when no table is set, the page metadata for that language.
 */
final class JobItem
{
    /** @param array<string, mixed> $row */
    public function __construct(private readonly array $row)
    {
    }

    public function uid(): int
    {
        return (int)($this->row['uid'] ?? 0);
    }

    public function jobId(): int
    {
        return (int)($this->row['job'] ?? 0);
    }

    public function table(): string
    {
        return (string)($this->row['table_name'] ?? '');
    }

    public function recordUid(): int
    {
        return (int)($this->row['record_uid'] ?? 0);
    }

    public function languageId(): int
    {
        return (int)($this->row['language_id'] ?? 0);
    }

    public function attempts(): int
    {
        return (int)($this->row['attempts'] ?? 0);
    }

    public function isPageMeta(): bool
    {
        return $this->table() === '' || $this->recordUid() <= 0;
    }

    public function label(): string
    {
        return $this->isPageMeta() ? '' : $this->table() . ':' . $this->recordUid();
    }
}
