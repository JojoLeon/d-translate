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

namespace Dreistein\DTranslate\Backend\FormEngine;

use Dreistein\DTranslate\Llm\ProviderDefaults;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Field wizard on the "Provider type" select of tx_dtranslate_llm_config.
 *
 * Renders no markup — it only hands the endpoint defaults and the two field
 * names to a small JS module which pre-fills "API base URL" when the provider
 * type changes and the URL field is still empty.
 */
final class LlmEndpointDefaultWizard extends AbstractNode
{
    public function render(): array
    {
        $result = $this->initializeResultArray();

        $typeField = (string)($this->data['parameterArray']['itemFormElName'] ?? '');
        if ($typeField === '') {
            return $result;
        }
        // The wizard sits on the type field; its sibling endpoint field shares
        // the same "data[table][uid]" prefix.
        $endpointField = preg_replace('/\[type\]$/', '[endpoint]', $typeField);
        if ($endpointField === null || $endpointField === $typeField) {
            return $result;
        }

        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create(
            '@dreistein/d-translate/llm-endpoint-default.js'
        )->invoke(null, [
            'typeField' => $typeField,
            'endpointField' => $endpointField,
            'defaults' => ProviderDefaults::endpoints(),
        ]);

        return $result;
    }
}
