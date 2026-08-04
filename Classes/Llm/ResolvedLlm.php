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

namespace Dreistein\DTranslate\Llm;

/**
 * Immutable, fully-resolved LLM binding for one chat purpose. Produced by
 * {@see \Dreistein\DTranslate\Service\ProfileLlmResolver} and consumed by
 * {@see LlmGateway}. Carries everything a wire call needs — no further DB or
 * settings lookup required.
 *
 * Copied (by design, no d-ai-pro dependency) from the d-ai-pro LLM core
 * and kept isolated in this namespace so it stays extractable into a shared
 * library later. Trimmed to the chat-only fields the translation path needs.
 */
final class ResolvedLlm
{
    public function __construct(
        public readonly string $type,
        public readonly string $label,
        public readonly string $protocol,
        public readonly string $endpoint,
        public readonly string $apiKey,
        public readonly string $chatModel,
    ) {
    }
}
