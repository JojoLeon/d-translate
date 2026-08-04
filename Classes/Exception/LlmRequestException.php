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

namespace Dreistein\DTranslate\Exception;

/**
 * An LLM call that failed with a diagnosis attached: a message written for the
 * editor in the backend (never a raw Guzzle/provider dump) plus the flag that
 * decides how the run reacts.
 *
 * fatal = the run cannot succeed by trying again (wrong key, unknown model,
 * exhausted quota) → abort the whole run and show the message.
 * non-fatal = transient (rate limit, provider hiccup, timeout) → skip that
 * field, collect a hint, keep going.
 */
class LlmRequestException extends TranslationException
{
    public function __construct(
        public readonly string $userMessage,
        public readonly bool $fatal,
        public readonly ?int $status = null,
        int $code = 0,
        ?\Throwable $previous = null,
        /** Seconds the provider asked the client to wait before retrying. */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($userMessage, $code, $previous);
    }
}
