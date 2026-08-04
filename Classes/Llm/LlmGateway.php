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

use Dreistein\DTranslate\Job\RunUsage;
use Dreistein\DTranslate\Llm\Client\AnthropicClient;
use Dreistein\DTranslate\Llm\Client\OpenAiCompatibleClient;

/**
 * Single entry point for talking to whatever LLM a {@see ResolvedLlm} points
 * at. Dispatches by protocol to the matching wire client. Translation services
 * depend on this gateway, never on a concrete provider — adding a provider type
 * is a resolver + (optionally) a client change; call sites stay untouched.
 */
final class LlmGateway
{
    public function __construct(
        private readonly AnthropicClient $anthropicClient,
        private readonly OpenAiCompatibleClient $openAiClient,
        private readonly RunUsage $usage,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options temperature, max_tokens, cache_system …
     */
    public function chat(ResolvedLlm $llm, array $messages, array $options = []): string
    {
        return $this->chatWithUsage($llm, $messages, $options)['answer'];
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array{answer: string, usage: array<string, mixed>}
     */
    public function chatWithUsage(ResolvedLlm $llm, array $messages, array $options = []): array
    {
        $result = match ($llm->protocol) {
            LlmProtocol::ANTHROPIC
                => $this->anthropicClient->chatWithUsage($llm->endpoint, $llm->apiKey, $llm->chatModel, $messages, $options),
            default
                => $this->openAiClient->chatWithUsage($llm->endpoint, $llm->apiKey, $llm->chatModel, $messages, $options),
        };
        // Every call is counted, wherever it was triggered from.
        $this->usage->add((array)($result['usage'] ?? []));
        return $result;
    }
}
