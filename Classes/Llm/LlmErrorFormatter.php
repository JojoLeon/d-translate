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

use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Localization\Messages;

/**
 * Turns provider failures into one sentence an editor can act on — which
 * provider, what went wrong, what to check — instead of the raw Guzzle text
 * ("Client error: `POST https://…` resulted in a `401 Unauthorized` …") that
 * used to reach the backend as an exception page.
 *
 * Secrets are masked: provider error bodies echo back the key that was sent.
 */
final class LlmErrorFormatter
{
    private const MAX_DETAIL = 240;

    public static function fromStatus(
        string $provider,
        string $model,
        string $url,
        int $status,
        string $body,
        ?int $retryAfter = null,
    ): LlmRequestException {
        $detail = self::detail($body);
        $suffix = $detail !== '' ? ' ' . Messages::tr('llm.providerMessage', $detail) : '';

        [$message, $fatal] = match (true) {
            $status === 401 || $status === 403 => [Messages::tr('llm.keyRejected', $provider, $status), true],
            // Gemini answers a bad key with 400, not 401, so the generic
            // bad-request branch below would send the editor hunting for a
            // wrong model name instead of the key they just mistyped.
            self::indicatesBadKey($body) => [Messages::tr('llm.keyRejected', $provider, $status), true],
            $status === 404 => [Messages::tr('llm.unknownModel', $provider, $model, $url), true],
            $status === 429 => [Messages::tr('llm.rateLimit', $provider), false],
            $status === 402 || self::indicatesQuota($body) => [Messages::tr('llm.quota', $provider, $status), true],
            $status === 400 || $status === 422 => [Messages::tr('llm.badRequest', $provider, $status, $model), true],
            $status >= 500 => [Messages::tr('llm.serverError', $provider, $status), false],
            default => [Messages::tr('llm.httpStatus', $provider, $status), false],
        };

        return new LlmRequestException($message . $suffix, $fatal, $status, 1718500032, null, $retryAfter);
    }

    public static function fromTransport(
        string $provider,
        string $url,
        \Throwable $previous,
    ): LlmRequestException {
        $raw = self::mask($previous->getMessage());
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        if (preg_match('/could not resolve host|name or service not known|ssl certificate/i', $raw)) {
            return new LlmRequestException(
                Messages::tr('llm.unreachable', $host, self::shorten($raw)),
                true,
                null,
                1718500031,
                $previous,
            );
        }
        // curl reports a stalled response as "Operation too slow", not as a timeout.
        if (preg_match('/timed? ?out|too slow/i', $raw)) {
            return new LlmRequestException(
                Messages::tr('llm.timeout', $provider),
                false,
                null,
                1718500031,
                $previous,
            );
        }

        return new LlmRequestException(
            Messages::tr('llm.connectionFailed', $provider, self::shorten($raw)),
            false,
            null,
            1718500031,
            $previous,
        );
    }

    /** Provider error text ("Incorrect API key provided: …"), masked and shortened. */
    private static function detail(string $body): string
    {
        $decoded = json_decode($body, true);
        // Gemini wraps its error object in a one-element list
        // ([{"error":{…}}]) — unwrap it, or the editor gets raw JSON instead of
        // "Please pass a valid API key".
        if (is_array($decoded) && array_is_list($decoded) && is_array($decoded[0] ?? null)) {
            $decoded = $decoded[0];
        }
        $message = '';
        if (is_array($decoded)) {
            $error = $decoded['error'] ?? null;
            if (is_array($error)) {
                $message = (string)($error['message'] ?? '');
            } elseif (is_string($error)) {
                $message = $error;
            }
            if ($message === '') {
                $message = (string)($decoded['message'] ?? '');
            }
        }
        if ($message === '') {
            $message = trim(strip_tags($body));
        }
        return $message === '' ? '' : self::shorten(self::mask($message));
    }

    private static function indicatesQuota(string $body): bool
    {
        return (bool)preg_match('/insufficient_quota|billing|credit balance/i', $body);
    }

    /**
     * A key problem reported under some other status code. Deliberately narrow:
     * it only fires on wording that is about the key itself, so a model or
     * parameter error keeps its own, more useful message.
     */
    private static function indicatesBadKey(string $body): bool
    {
        return (bool)preg_match(
            '/(invalid|expired|missing|valid).{0,20}api[ _-]?key|api[ _-]?key.{0,20}(invalid|expired|missing|not valid)|API_KEY_INVALID/i',
            $body,
        );
    }

    /** Never let a key — plain, hashed or bearer — travel into a flash message or log line. */
    private static function mask(string $text): string
    {
        $patterns = [
            '/\bsk-[A-Za-z0-9_\-]{6,}/' => 'sk-***',
            '/\$argon2[a-z0-9]*\$[^\s"\']+/i' => '[hash]',
            '/\$2[aby]\$[^\s"\']+/' => '[hash]',
            '/\bBearer\s+\S+/i' => 'Bearer ***',
        ];
        return (string)preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    private static function shorten(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= self::MAX_DETAIL) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_DETAIL) . ' …';
    }
}
