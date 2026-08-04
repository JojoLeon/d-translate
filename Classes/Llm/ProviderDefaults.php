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
 * Default API base URLs per provider type. Single source of truth for the
 * fallback used at runtime (ProfileLlmResolver) and for the value the backend
 * form pre-fills when an editor picks a provider type.
 */
final class ProviderDefaults
{
    private const ENDPOINTS = [
        'anthropic' => 'https://api.anthropic.com/v1',
        'openai' => 'https://api.openai.com/v1',
        'mistral' => 'https://api.mistral.ai/v1',
        // Google's OpenAI-compatible layer, not the native generateContent API:
        // it speaks /chat/completions with a Bearer key, so Gemini needs no
        // client of its own.
        'gemini' => 'https://generativelanguage.googleapis.com/v1beta/openai',
    ];

    public static function endpoint(string $type): string
    {
        return self::ENDPOINTS[$type] ?? self::ENDPOINTS['anthropic'];
    }

    /** @return array<string, string> */
    public static function endpoints(): array
    {
        return self::ENDPOINTS;
    }
}
