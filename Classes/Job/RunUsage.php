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

use TYPO3\CMS\Core\SingletonInterface;

/**
 * Request-scoped counter for what a run costs: every gateway call adds its
 * token usage here, the job store persists it per job. Without this a large
 * run is a black box until the provider's invoice arrives.
 */
final class RunUsage implements SingletonInterface
{
    private int $tokensIn = 0;
    private int $tokensOut = 0;
    private int $calls = 0;

    /** @param array<string, mixed> $usage provider usage block */
    public function add(array $usage): void
    {
        $this->calls++;
        $this->tokensIn += (int)($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $this->tokensOut += (int)($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
    }

    public function tokensIn(): int
    {
        return $this->tokensIn;
    }

    public function tokensOut(): int
    {
        return $this->tokensOut;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function reset(): void
    {
        $this->tokensIn = 0;
        $this->tokensOut = 0;
        $this->calls = 0;
    }
}
