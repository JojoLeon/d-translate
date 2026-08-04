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

namespace Dreistein\DTranslate\Service;

use Dreistein\DTranslate\Llm\LlmGateway;
use Dreistein\DTranslate\Llm\ResolvedProfile;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Builds the translation prompt (profile + glossary) and calls the LLM gateway.
 * For RTE/HTML fields it verifies tag integrity and retries once with a
 * stricter instruction before giving up — LLMs occasionally drop or alter
 * attributes/tags.
 */
final class TranslationService
{
    /** Segments stay valid until the source text or the profile changes. */
    private const CACHE_LIFETIME = 2592000;

    private const BATCH_INSTRUCTION = 'The user message is a JSON object of fields. Return ONLY a JSON object with '
        . 'exactly the same keys, each value translated. Never merge, reorder, add or drop keys.';

    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly LoggerInterface $logger,
        private readonly FrontendInterface $segmentCache,
    ) {
    }

    public function translateField(
        string $sourceText,
        ResolvedProfile $profile,
        string $targetLanguage,
        string $glossaryBlock = '',
        string $pageContext = '',
        bool $containsHtml = false
    ): string {
        if (trim($sourceText) === '') {
            return $sourceText;
        }

        // The system prompt is identical for every field of a page (language,
        // formality, style, glossary, page context) so Anthropic prompt caching
        // bills the large prefix once and each field call is cheap. The only
        // per-call variation is the user message — never the system prompt.
        // Always on: a translation run repeats the same prefix dozens of times,
        // which is exactly the case prompt caching exists for. OpenAI-compatible
        // providers ignore the flag and cache prefixes on their own.
        $system = $this->systemPrompt($profile, $targetLanguage, $glossaryBlock, $pageContext);
        $options = ['temperature' => 0.0, 'cache_system' => true];

        // Repeated segments (buttons, labels, boilerplate) are common on a page
        // and identical across records; paying for them once per run is enough.
        $cacheKey = $this->cacheKey($system, $sourceText);
        $cached = $this->cachedSegment($cacheKey, $sourceText);
        if ($cached !== null) {
            return $cached;
        }

        $result = trim($this->gateway->chat($profile->llm, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $sourceText],
        ], $options));

        if ($this->isImplausiblyLong($sourceText, $result)) {
            // The model answered with something other than the field — with a
            // short user message (a product name, a label) it tends to translate
            // the page context from the system prompt instead, despite being told
            // not to. One focused retry, then keep the source: an untranslated
            // field is recoverable, a 3000-character "headline" is not.
            $retry = trim($this->gateway->chat($profile->llm, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Translate ONLY the text between the markers. Output the translation "
                    . "alone — no markers, no explanation, and nothing from the page context.\n\n"
                    . "-----TEXT-----\n" . $sourceText . "\n-----END-----"],
            ], $options));
            $retry = $this->stripTextMarkers($retry);
            if ($retry !== '' && !$this->isImplausiblyLong($sourceText, $retry)) {
                $result = $retry;
            } else {
                $this->logger->warning('d-translate: translation implausibly long, keeping source', [
                    'sourceLength' => mb_strlen($sourceText),
                    'resultLength' => mb_strlen($result),
                    'source' => mb_substr($sourceText, 0, 120),
                    'result' => mb_substr($result, 0, 200),
                ]);
                // Deliberately not cached — a later run may do better.
                return $sourceText;
            }
        }

        if ($containsHtml && !$this->tagsMatch($sourceText, $result)) {
            // One reinforced retry before accepting a structurally-changed result
            // (the extra instruction goes in the user turn to keep the system cached).
            $retry = trim($this->gateway->chat($profile->llm, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "Translate the following. CRITICAL: output the SAME HTML tags, "
                    . "in the SAME order, with identical attributes; translate text content only.\n\n" . $sourceText],
            ], $options));
            if ($this->tagsMatch($sourceText, $retry)) {
                return $retry;
            }
            $this->logger->warning('d-translate: HTML tag structure changed by translation', [
                'sourceTags' => $this->tagSkeleton($sourceText),
                'resultTags' => $this->tagSkeleton($result),
            ]);
        }

        if ($result !== '') {
            $this->segmentCache->set($cacheKey, $result, ['dtranslate'], self::CACHE_LIFETIME);
        }
        return $result;
    }

    /**
     * Cache identity = prompt + source text. The system prompt already carries
     * language, formality, style, glossary and page context, so a changed
     * profile or a different page never reuses another run's translation.
     */
    private function cacheKey(string $system, string $sourceText): string
    {
        return 'seg_' . hash('xxh3', $system . "\x00" . $sourceText);
    }


    /**
     * Translate several fields of one record in a single call.
     *
     * A record has 3–6 translatable fields; asking for them one by one is the
     * single biggest cost driver of a large run. The model answers with a JSON
     * object keyed exactly like the input, and anything it drops, mangles or
     * returns with a broken tag structure falls back to the per-field path — so
     * batching can save calls but never lose a field.
     *
     * @param array<string, string> $texts     field name => source text
     * @param array<string, bool>   $htmlFlags field name => contains markup
     * @return array<string, string> field name => translation
     */
    public function translateFields(
        array $texts,
        ResolvedProfile $profile,
        string $targetLanguage,
        string $glossaryBlock = '',
        string $pageContext = '',
        array $htmlFlags = []
    ): array {
        $texts = array_filter($texts, static fn (string $v): bool => trim($v) !== '');
        if ($texts === []) {
            return [];
        }

        $system = $this->systemPrompt($profile, $targetLanguage, $glossaryBlock, $pageContext);
        $result = [];
        $pending = [];
        foreach ($texts as $field => $text) {
            $cached = $this->cachedSegment($this->cacheKey($system, $text), $text);
            if ($cached !== null) {
                $result[$field] = $cached;
                continue;
            }
            $pending[$field] = $text;
        }
        if ($pending === []) {
            return $result;
        }
        if (count($pending) === 1) {
            $field = array_key_first($pending);
            $result[$field] = $this->translateField(
                $pending[$field],
                $profile,
                $targetLanguage,
                $glossaryBlock,
                $pageContext,
                (bool)($htmlFlags[$field] ?? false),
            );
            return $result;
        }

        $answer = trim($this->gateway->chat($profile->llm, [
            ['role' => 'system', 'content' => $system . "\n" . self::BATCH_INSTRUCTION],
            ['role' => 'user', 'content' => (string)json_encode($pending, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], ['temperature' => 0.0, 'cache_system' => true]));

        $decoded = json_decode($this->stripCodeFence($answer), true);
        foreach ($pending as $field => $source) {
            $translated = is_array($decoded) && is_string($decoded[$field] ?? null) ? trim($decoded[$field]) : '';
            $isHtml = (bool)($htmlFlags[$field] ?? false);
            if ($translated === ''
                || $this->isImplausiblyLong($source, $translated)
                || ($isHtml && !$this->tagsMatch($source, $translated))
            ) {
                // Not usable — this field goes through the reliable path.
                $result[$field] = $this->translateField($source, $profile, $targetLanguage, $glossaryBlock, $pageContext, $isHtml);
                continue;
            }
            $result[$field] = $translated;
            $this->segmentCache->set($this->cacheKey($system, $source), $translated, ['dtranslate'], self::CACHE_LIFETIME);
        }
        return $result;
    }

    /**
     * A cached segment, or null when there is nothing usable.
     *
     * The length check applies to reads as well: an entry written before this
     * guard existed would otherwise be served for 30 days and fail the same
     * write on every run. Dropping it lets the next call earn a fresh answer.
     */
    private function cachedSegment(string $cacheKey, string $sourceText): ?string
    {
        $cached = $this->segmentCache->get($cacheKey);
        if (!is_string($cached) || $cached === '') {
            return null;
        }
        if ($this->isImplausiblyLong($sourceText, $cached)) {
            $this->logger->warning('d-translate: dropping an implausible cached translation', [
                'sourceLength' => mb_strlen($sourceText),
                'cachedLength' => mb_strlen($cached),
                'source' => mb_substr($sourceText, 0, 120),
            ]);
            $this->segmentCache->remove($cacheKey);
            return null;
        }
        return $cached;
    }

    /**
     * A translation is never a multiple of its source length. When it is, the
     * answer is not a translation of this field: the classic case is a very
     * short user message ("LUMINA") next to a long page context, where the model
     * translates the context instead. Such a value also breaks the write —
     * `header` and `title` are varchar(255).
     *
     * The allowance is generous on purpose: short strings can legitimately grow
     * ("Yes" → "Ja, gerne"), so only a blow-out is treated as wrong.
     */
    private function isImplausiblyLong(string $source, string $result): bool
    {
        return mb_strlen($result) > 3 * mb_strlen($source) + 40;
    }

    /** The focused retry asks for markers to be dropped; some models keep them. */
    private function stripTextMarkers(string $text): string
    {
        $text = preg_replace('/^-{3,}\s*TEXT\s*-{3,}\s*/i', '', trim($text)) ?? $text;
        return trim(preg_replace('/\s*-{3,}\s*END\s*-{3,}$/i', '', $text) ?? $text);
    }

    /** Models like to wrap JSON in ```json fences even when told not to. */
    private function stripCodeFence(string $text): string
    {
        if (!str_starts_with($text, '```')) {
            return $text;
        }
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text) ?? $text;
        return trim(preg_replace('/```\s*$/', '', $text) ?? $text);
    }

    private function systemPrompt(ResolvedProfile $profile, string $targetLanguage, string $glossaryBlock, string $pageContext): string
    {
        $lines = [
            sprintf(
                'You are a professional translator. Translate the user message from %s into %s.',
                $profile->sourceLanguage,
                $targetLanguage
            ),
            'Return ONLY the translation — no explanations, no quotes, no preamble.',
            'Preserve meaning and tone. Do not translate proper nouns, brand names, URLs or code.',
            'If the text contains HTML markup, translate only the human-readable text nodes; '
                . 'keep all tags, attributes and entities exactly as they are.',
        ];
        $formality = match ($profile->formality) {
            'formal' => 'Use a formal register of address.',
            'informal' => 'Use an informal register of address.',
            default => '',
        };
        if ($formality !== '') {
            $lines[] = $formality;
        }
        if ($profile->styleHint !== '') {
            $lines[] = 'Additional guidance: ' . $profile->styleHint;
        }
        if ($glossaryBlock !== '') {
            $lines[] = $glossaryBlock;
        }
        if ($pageContext !== '') {
            $lines[] = "The user messages are individual content elements of one web page. "
                . "Below is the full page (source language) for context — use it to keep terminology, tone, "
                . "pronoun references and headings coherent across elements. Do NOT translate or repeat the "
                . "context; translate only each user message:\n\n----- PAGE CONTEXT -----\n" . $pageContext;
        }
        return implode("\n", $lines);
    }

    /** Tag skeleton: ordered, lowercased open/close tag names — ignores text and attributes. */
    private function tagsMatch(string $a, string $b): bool
    {
        return $this->tagSkeleton($a) === $this->tagSkeleton($b);
    }

    private function tagSkeleton(string $html): string
    {
        if (!preg_match_all('/<(\/?[a-z][a-z0-9]*)\b[^>]*>/i', $html, $m)) {
            return '';
        }
        return strtolower(implode(',', $m[1]));
    }
}
