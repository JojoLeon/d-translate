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
 * One translation run: the page, the target languages and the records the
 * editor selected. The unit of work is the item (record × language), not the
 * job — that is what keeps a request short and a run resumable.
 */
final class TranslationJob
{
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    /** @param array<string, mixed> $row */
    public function __construct(private readonly array $row)
    {
    }

    public function uid(): int
    {
        return (int)($this->row['uid'] ?? 0);
    }

    public function pageId(): int
    {
        return (int)($this->row['page_id'] ?? 0);
    }

    public function profileUid(): int
    {
        return (int)($this->row['profile'] ?? 0);
    }

    public function state(): string
    {
        return (string)($this->row['state'] ?? self::STATE_QUEUED);
    }

    public function publishPage(): bool
    {
        return (bool)($this->row['publish_page'] ?? false);
    }

    /** Leave records that already have a translation untouched. */
    public function skipTranslated(): bool
    {
        return (bool)($this->row['skip_translated'] ?? false);
    }

    public function isBackground(): bool
    {
        return (bool)($this->row['background'] ?? false);
    }

    public function total(): int
    {
        return (int)($this->row['total'] ?? 0);
    }

    public function done(): int
    {
        return (int)($this->row['done'] ?? 0);
    }

    public function failed(): int
    {
        return (int)($this->row['failed'] ?? 0);
    }

    public function tokensIn(): int
    {
        return (int)($this->row['tokens_in'] ?? 0);
    }

    public function tokensOut(): int
    {
        return (int)($this->row['tokens_out'] ?? 0);
    }

    public function calls(): int
    {
        return (int)($this->row['calls'] ?? 0);
    }

    public function message(): string
    {
        return (string)($this->row['message'] ?? '');
    }

    public function crdate(): int
    {
        return (int)($this->row['crdate'] ?? 0);
    }

    /** @return list<int> */
    public function languages(): array
    {
        $ids = array_filter(explode(',', (string)($this->row['languages'] ?? '')), 'is_numeric');
        return array_values(array_map('intval', $ids));
    }

    public function isOpen(): bool
    {
        return in_array($this->state(), [self::STATE_QUEUED, self::STATE_RUNNING], true);
    }

    public function percent(): int
    {
        $total = $this->total();
        return $total > 0 ? (int)round(($this->done() + $this->failed()) / $total * 100) : 0;
    }
}
