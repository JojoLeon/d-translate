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
 * Transport for OpenAI-compatible REST APIs (/chat/completions, /embeddings).
 * Mistral, OpenAI and local servers (Ollama, vLLM, …) all speak this dialect.
 * Endpoint, key and model are supplied per call — the client is stateless.
 *
 * Copied from the d-ai-pro LLM core (no dependency) and kept isolated in this
 * namespace.
 */
final class OpenAiCompatibleClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
        private readonly HttpOptions $httpOptions,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     */
    public function chat(string $baseUrl, string $apiKey, string $model, array $messages, array $options = []): string
    {
        return (string)$this->chatWithUsage($baseUrl, $apiKey, $model, $messages, $options)['answer'];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{answer: string, usage: array<string, mixed>}
     */
    public function chatWithUsage(string $baseUrl, string $apiKey, string $model, array $messages, array $options = []): array
    {
        // `cache_system` is an OpenAI/Mistral no-op (implicit prefix cache).
        unset($options['cache_system']);
        $httpTimeout = isset($options['http_timeout']) ? (int)$options['http_timeout'] : null;
        unset($options['http_timeout']);

        $payload = array_merge([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
        ], $options);
        $payload['model'] = $options['model'] ?? $model;
        // OpenAI GPT-5 / o-series reject a custom `temperature` and legacy
        // `max_tokens` (they require `max_completion_tokens`) with HTTP 400.
        if (preg_match('/^(gpt-5|o[0-9])/i', (string)$payload['model'])) {
            unset($payload['temperature']);
            if (isset($payload['max_tokens'])) {
                $payload['max_completion_tokens'] = $payload['max_tokens'];
                unset($payload['max_tokens']);
            }
        }

        $response = $this->request($baseUrl, $apiKey, '/chat/completions', $payload, $httpTimeout);
        $choices = $response['choices'] ?? [];
        $content = is_array($choices[0] ?? null) ? ($choices[0]['message']['content'] ?? '') : '';
        return [
            'answer' => is_string($content) ? $content : '',
            'usage' => (array)($response['usage'] ?? []),
        ];
    }

    /**
     * @param list<string> $texts
     * @return list<list<float>>
     */
    public function embed(string $baseUrl, string $apiKey, string $model, array $texts): array
    {
        if ($texts === []) {
            return [];
        }
        $response = $this->request($baseUrl, $apiKey, '/embeddings', [
            'model' => $model,
            'input' => $texts,
        ]);
        $vectors = [];
        foreach ($response['data'] ?? [] as $row) {
            $vectors[] = array_map('floatval', $row['embedding'] ?? []);
        }
        return $vectors;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    /**
     * Transient failures (rate limit, provider hiccup, timeout) are retried with
     * a backoff before they reach the caller — a run of thousands of calls hits
     * those regularly, and every unretried one is a field left untranslated.
     */
    private function request(string $baseUrl, string $apiKey, string $endpoint, array $payload, ?int $timeout = null): array
    {
        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->requestOnce($baseUrl, $apiKey, $endpoint, $payload, $timeout);
            } catch (LlmRequestException $e) {
                if ($e->fatal || $attempt >= $this->httpOptions->maxAttempts()) {
                    throw $e;
                }
                $wait = $this->httpOptions->backoffSeconds($attempt, $e->retryAfter);
                $this->logger->warning('OpenAI-compatible request retried', [
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
    private function requestOnce(string $baseUrl, string $apiKey, string $endpoint, array $payload, ?int $timeout = null): array
    {
        $provider = $this->providerLabel($baseUrl);
        $model = (string)($payload['model'] ?? '');
        if (trim($apiKey) === '') {
            throw new LlmRequestException(Messages::tr('llm.noApiKey'), true, null, 1718500030);
        }
        $url = rtrim($baseUrl, '/') . $endpoint;

        try {
            $response = $this->requestFactory->request($url, 'POST', array_merge([
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'body' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $this->httpOptions->forRequest($timeout)));
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI-compatible request failed', ['url' => $url, 'exception' => $e->getMessage()]);
            throw LlmErrorFormatter::fromTransport($provider, $url, $e);
        }

        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('OpenAI-compatible non-success status', ['status' => $status, 'url' => $url, 'body' => $body]);
            throw LlmErrorFormatter::fromStatus($provider, $model, $baseUrl, $status, $body, $this->retryAfter($response));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new LlmRequestException(Messages::tr('llm.invalidJson', $provider), false, $status, 1718500033);
        }
        return $decoded;
    }

    /** Seconds the provider asked us to wait, if it said so. */
    private function retryAfter(\Psr\Http\Message\ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        return $header !== '' && ctype_digit($header) ? (int)$header : null;
    }

    /** Names the provider in error messages by its host — OpenAI, Gemini, Mistral, or the bare host of a self-hosted endpoint. */
    private function providerLabel(string $baseUrl): string
    {
        $host = (string)(parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl);
        return match (true) {
            // Checked before "openai": Google's compatible endpoint carries
            // that word in its PATH, never in the host.
            str_contains($host, 'googleapis') => 'Gemini',
            str_contains($host, 'openai') => 'OpenAI',
            str_contains($host, 'mistral') => 'Mistral',
            default => $host !== '' ? $host : 'LLM',
        };
    }
}
