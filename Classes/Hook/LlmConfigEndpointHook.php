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

namespace Dreistein\DTranslate\Hook;

use Dreistein\DTranslate\Llm\EndpointUrl;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * DataHandler datamap hook: stores the "API base URL" of an LLM configuration
 * the way the clients use it. A pasted ".../v1/chat/completions" is saved as
 * ".../v1", so the form shows what is actually called.
 */
final class LlmConfigEndpointHook
{
    /** @param array<string, mixed> $fieldArray */
    public function processDatamap_preProcessFieldArray(
        array &$fieldArray,
        string $table,
        int|string $id,
        DataHandler $dataHandler
    ): void {
        if ($table !== 'tx_dtranslate_llm_config' || !isset($fieldArray['endpoint'])) {
            return;
        }
        $fieldArray['endpoint'] = EndpointUrl::normalize((string)$fieldArray['endpoint']);
    }
}
