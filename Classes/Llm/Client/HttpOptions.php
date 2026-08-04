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

namespace Dreistein\DTranslate\Llm\Client;

use Dreistein\DTranslate\Configuration\ExtensionSettings;

/**
 * Transport limits for every LLM call, in one place.
 *
 * A translation run is a long chain of HTTP calls inside one backend request,
 * so a single call that never returns freezes the whole run — that is what a
 * bare `timeout` did not prevent:
 *
 *  - `timeout` alone is a *stream* timeout on Guzzle's stream handler (per read,
 *    not total), so a provider trickling bytes could stall indefinitely;
 *  - `connect_timeout` defaults to TYPO3's 10s, but was not pinned to our budget.
 *
 * Every limit below is therefore set explicitly, for both handlers, and the
 * total budget can never be "unlimited". Deliberately NO curl low-speed guard:
 * non-streaming LLM responses send zero bytes while the model generates —
 * legitimately for minutes on long texts — so a bytes/sec floor aborts exactly
 * the expensive calls. A real mid-response stall is bounded by the total
 * budget instead.
 */
final class HttpOptions
{
    public function __construct(
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * @param int|null $timeout per-call override (e.g. a long OCR job); still clamped
     * @return array<string, mixed> Guzzle options
     */
    public function forRequest(?int $timeout = null): array
    {
        $total = $timeout !== null && $timeout > 0
            ? max(5, min($timeout, 600))
            : $this->settings->requestTimeout();
        $connect = min($this->settings->connectTimeout(), $total);

        return [
            'timeout' => $total,
            'connect_timeout' => $connect,
            // Stream handler: without this the "timeout" above only limits a
            // single read, not the whole response.
            'read_timeout' => $total,
            'curl' => [
                CURLOPT_TIMEOUT => $total,
                CURLOPT_CONNECTTIMEOUT => $connect,
                // Timeouts must not be implemented with signals in a
                // possibly threaded SAPI.
                CURLOPT_NOSIGNAL => true,
            ],
            // Read the error body ourselves instead of letting Guzzle throw a
            // RequestException whose message is a URL dump.
            'http_errors' => false,
        ];
    }

    public function defaultTimeout(): int
    {
        return $this->settings->requestTimeout();
    }

    /**
     * Sleep before retry number $attempt (1-based): 2s, 4s, 8s — capped. A run
     * of thousands of calls will meet a rate limit; without a backoff those
     * fields silently stay in the source language.
     */
    public function backoffSeconds(int $attempt, ?int $retryAfter = null): int
    {
        if ($retryAfter !== null && $retryAfter > 0) {
            return min($retryAfter, 60);
        }
        return min(2 ** max(1, $attempt), 30);
    }

    public function maxAttempts(): int
    {
        return 3;
    }
}
