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

/**
 * Builds the glossary section of the system prompt for a profile + target
 * language. Full-inject strategy: small glossaries are cheap, especially with
 * Anthropic prompt caching (cache_system). A pgvector retrieval mode for very
 * large glossaries can replace glossaryBlock() later without touching callers.
 */
final class GlossaryProvider
{
    public function __construct(
        private readonly GlossaryStore $glossaryStore,
    ) {
    }

    public function glossaryBlock(int $profileUid, int $targetLanguageId): string
    {
        $terms = $this->glossaryStore->findForProfile($profileUid, $targetLanguageId);
        if ($terms === []) {
            return '';
        }
        $lines = ['Glossary — translate these terms exactly as given and apply them consistently:'];
        foreach ($terms as $t) {
            $line = sprintf('- "%s" → "%s"', $t['source_term'], $t['target_term']);
            if ($t['note'] !== '') {
                $line .= ' (' . $t['note'] . ')';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }
}
