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
 * Wire protocols the gateway can dispatch to. The Anthropic Messages API
 * differs enough from the OpenAI dialect (auth, system prompt, response shape)
 * to warrant its own client; Mistral/OpenAI share the OpenAI dialect.
 */
final class LlmProtocol
{
    public const ANTHROPIC = 'anthropic';
    public const OPENAI = 'openai';
}
