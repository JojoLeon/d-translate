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

namespace Dreistein\DTranslate\Configuration;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Typed accessor for the d_translate extension configuration
 * (ext_conf_template.txt): fallback provider credentials + language hints.
 * A tx_dtranslate_profile record supersedes these settings per site.
 */
final class ExtensionSettings
{
    /** @var array<string, mixed> */
    private array $config;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $raw = $extensionConfiguration->get('d_translate');
            $this->config = is_array($raw) ? $raw : [];
        } catch (\Throwable) {
            $this->config = [];
        }
    }

    public function anthropicApiKey(): string
    {
        return trim((string)($this->config['anthropic']['apiKey'] ?? ''));
    }

    public function anthropicApiBase(): string
    {
        $base = trim((string)($this->config['anthropic']['apiBase'] ?? ''));
        return $base !== '' ? $base : 'https://api.anthropic.com/v1';
    }

    public function anthropicChatModel(): string
    {
        $model = trim((string)($this->config['anthropic']['chatModel'] ?? ''));
        return $model !== '' ? $model : 'claude-sonnet-4-6';
    }

    public function anthropicTimeout(): int
    {
        $t = (int)($this->config['anthropic']['timeout'] ?? 0);
        // 180, not 60: non-streaming providers send nothing while the model
        // generates, and a long article on a large model exceeds a minute.
        return $t > 0 ? $t : 180;
    }

    /**
     * Whether a newly created page translation goes live right away. Only the
     * default for the module checkbox — the editor decides per run.
     */
    public function publishPages(): bool
    {
        return (bool)($this->config['behaviour']['publishPages'] ?? false);
    }

    /** Hard stop for a single run: 0 = no cap. Protects against a runaway selection. */
    public function maxCallsPerJob(): int
    {
        return max(0, (int)($this->config['behaviour']['maxCallsPerJob'] ?? 0));
    }

    /** Whether the module's "process in the background" box starts checked. */
    public function backgroundDefault(): bool
    {
        return (bool)($this->config['behaviour']['backgroundDefault'] ?? false);
    }

    /**
     * Above this many units a run that is *not* handed to the worker asks the
     * editor to confirm first — that is the case where the browser tab has to
     * stay open for the whole run. 0 turns the question off.
     */
    public function confirmAboveUnits(): int
    {
        return max(0, (int)($this->config['behaviour']['confirmAboveUnits'] ?? 50));
    }

    /**
     * Total budget for one LLM HTTP request. Clamped: a 0 here used to mean
     * "wait forever" further down the stack, which is how a single stuck
     * response could block a translation run indefinitely.
     */
    public function requestTimeout(): int
    {
        $t = (int)($this->config['behaviour']['requestTimeout'] ?? 0);
        if ($t <= 0) {
            $t = $this->anthropicTimeout();
        }
        return max(5, min($t, 600));
    }

    /** Time allowed for establishing the connection — always well below the total budget. */
    public function connectTimeout(): int
    {
        $t = (int)($this->config['behaviour']['connectTimeout'] ?? 0);
        if ($t <= 0) {
            $t = 10;
        }
        return max(2, min($t, $this->requestTimeout()));
    }

    /**
     * Last-resort source language name. Empty by default on purpose: a guessed
     * language silently tells the model to translate "from German" when the
     * texts are English. The run reports the gap instead.
     */
    public function sourceLanguage(): string
    {
        return trim((string)($this->config['fallback']['sourceLanguage'] ?? ''));
    }

    public function fallbackTargetLanguage(): string
    {
        return trim((string)($this->config['fallback']['targetLanguage'] ?? ''));
    }
}
