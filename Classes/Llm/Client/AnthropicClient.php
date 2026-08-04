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

use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Llm\LlmErrorFormatter;
use Dreistein\DTranslate\Localization\Messages;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Transport for the Anthropic Messages API (Claude). Auth via `x-api-key`, an
 * `anthropic-version` header, a required `max_tokens`, the system prompt as a
 * top-level field (not a message), and a `content` block array in the response.
 *
 * Copied from the d-ai-pro LLM core (no dependency) and kept isolated in this
 * namespace. Chat only — Anthropic has no embeddings endpoint.
 */
final class AnthropicClient
{
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly HttpOptions $httpOptions,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options temperature, max_tokens, model, cache_system …
     */
    public function chat(string $baseUrl, string $apiKey, string $model, array $messages, array $options = []): string
    {
        return (string)$this->chatWithUsage($baseUrl, $apiKey, $model, $messages, $options)['answer'];
    }

    /**
     * Like chat(), but also returns a normalized usage block. With option
     * `cache_system`, the system prompt is sent as a cache-controlled block so
     * Anthropic's explicit prompt cache bills repeat prefixes at ~10 % — the
     * lever that makes a large glossary header economical across many segments.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{answer: string, usage: array<string, mixed>}
     */
    public function chatWithUsage(string $baseUrl, string $apiKey, string $model, array $messages, array $options = []): array
    {
        if (trim($apiKey) === '') {
            throw new LlmRequestException(Messages::tr('llm.noApiKey'), true, null, 1718500010);
        }
        $httpTimeout = isset($options['http_timeout']) ? (int)$options['http_timeout'] : null;
        unset($options['http_timeout']);

        // Anthropic carries the system prompt as a top-level field; only
        // user/assistant turns belong in `messages`.
        $systemParts = [];
        $turns = [];
        foreach ($messages as $m) {
            $role = (string)($m['role'] ?? 'user');
            $content = (string)($m['content'] ?? '');
            if ($role === 'system') {
                if ($content !== '') {
                    $systemParts[] = $content;
                }
                continue;
            }
            $turns[] = ['role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }

        $payload = [
            'model' => (string)($options['model'] ?? $model),
            'max_tokens' => (int)($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
            'messages' => $turns,
        ];
        if ($systemParts !== []) {
            $systemText = implode("\n\n", $systemParts);
            if (!empty($options['cache_system'])) {
                $payload['system'] = [[
                    'type' => 'text',
                    'text' => $systemText,
                    'cache_control' => ['type' => 'ephemeral'],
                ]];
            } else {
                $payload['system'] = $systemText;
            }
        }
        // Claude 4+ models (model-first naming, e.g. claude-opus-4-8, claude-sonnet-4-6)
        // reject `temperature` with HTTP 400. Only forward it for older claude-3* ids.
        if (isset($options['temperature'])
            && !preg_match('/^claude-(opus|sonnet|haiku)-/i', (string)$payload['model'])
        ) {
            $payload['temperature'] = (float)$options['temperature'];
        }

        $url = rtrim($baseUrl, '/') . '/messages';
        $decoded = $this->post($url, $apiKey, $payload, $httpTimeout);

        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string)($block['text'] ?? '');
            }
        }

        $u = (array)($decoded['usage'] ?? []);
        $inputTokens = (int)($u['input_tokens'] ?? 0);
        $cacheRead = (int)($u['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int)($u['cache_creation_input_tokens'] ?? 0);
        $outputTokens = (int)($u['output_tokens'] ?? 0);
        $usage = [
            'prompt_tokens' => $inputTokens + $cacheRead + $cacheWrite,
            'completion_tokens' => $outputTokens,
            'total_tokens' => $inputTokens + $cacheRead + $cacheWrite + $outputTokens,
            'cached_tokens' => $cacheRead,
        ];

        return ['answer' => $text, 'usage' => $usage];
    }

    /**
     * Transient failures (rate limit, provider hiccup, timeout) are retried with
     * a backoff before they reach the caller — a run of thousands of calls hits
     * those regularly, and every unretried one is a field left untranslated.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $url, string $apiKey, array $payload, ?int $httpTimeout): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->postOnce($url, $apiKey, $payload, $httpTimeout);
            } catch (LlmRequestException $e) {
                if ($e->fatal || $attempt >= $this->httpOptions->maxAttempts()) {
                    throw $e;
                }
                $wait = $this->httpOptions->backoffSeconds($attempt, $e->retryAfter);
                $this->logger->warning('Anthropic request retried', [
                    'attempt' => $attempt,
                    'waitSeconds' => $wait,
                    'reason' => $e->getMessage(),
                ]);
                sleep($wait);
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postOnce(string $url, string $apiKey, array $payload, ?int $httpTimeout): array
    {
        try {
            $response = $this->requestFactory->request($url, 'POST', array_merge([
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $this->httpOptions->forRequest($httpTimeout)));
        } catch (\Throwable $e) {
            $this->logger->error('Anthropic request failed', ['url' => $url, 'exception' => $e->getMessage()]);
            throw LlmErrorFormatter::fromTransport('Anthropic', $url, $e);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('Anthropic non-success status', ['status' => $status, 'url' => $url, 'body' => $body]);
            $retryAfter = trim($response->getHeaderLine('Retry-After'));
            throw LlmErrorFormatter::fromStatus(
                'Anthropic',
                (string)$payload['model'],
                $url,
                $status,
                $body,
                $retryAfter !== '' && ctype_digit($retryAfter) ? (int)$retryAfter : null,
            );
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new LlmRequestException(Messages::tr('llm.invalidJson', 'Anthropic'), false, $status, 1718500013);
        }
        return $decoded;
    }
}
