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
 * Turns the configured "API base URL" into the URL a wire client calls.
 *
 * Provider consoles (Azure AI Foundry, OpenAI, Mistral …) show the full
 * operation URL, and that is what editors paste. The clients append the
 * operation themselves, so ".../openai/v1/chat/completions" used to become
 * ".../chat/completions/chat/completions" — a 404 whose message pointed at a
 * base URL that looked right. A trailing operation path is therefore cut off,
 * and a query string (Azure's "?api-version=…") stays at the end of the URL.
 */
final class EndpointUrl
{
    /** Operation paths the clients append — "/chat/completions" before "/completions". */
    private const OPERATIONS = ['/chat/completions', '/completions', '/embeddings', '/messages', '/responses'];

    /** Base URL without trailing slash or operation path. */
    public static function normalize(string $endpoint): string
    {
        [$path, $query] = self::split(trim($endpoint));
        $path = rtrim($path, '/');
        foreach (self::OPERATIONS as $operation) {
            if (str_ends_with(strtolower($path), $operation)) {
                $path = rtrim(substr($path, 0, -strlen($operation)), '/');
                break;
            }
        }
        return $path . $query;
    }

    /** Request URL for one operation, e.g. join($base, '/chat/completions'). */
    public static function join(string $endpoint, string $operation): string
    {
        [$path, $query] = self::split(self::normalize($endpoint));
        return $path . '/' . ltrim($operation, '/') . $query;
    }

    /** @return array{0: string, 1: string} path part and query string including "?" */
    private static function split(string $url): array
    {
        $pos = strpos($url, '?');
        return $pos === false ? [$url, ''] : [substr($url, 0, $pos), substr($url, $pos)];
    }
}
