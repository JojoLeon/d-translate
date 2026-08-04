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

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * Translates the text/RTE values inside a FlexForm value, guided by the field's
 * Data Structure so only genuine input/text/RTE leaves are touched (never
 * selects, checkboxes or other settings). The actual string translation is
 * delegated to a callback so this stays decoupled from the LLM layer.
 */
final class FlexFormTranslator
{
    public function __construct(
        private readonly FlexFormTools $flexFormTools,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $row    the record holding the FlexForm (for DS resolution)
     * @param callable(string, bool): string $translate (value, isHtml) => translation
     */
    public function translate(string $xml, string $table, string $field, array $row, callable $translate): string
    {
        if (trim($xml) === '') {
            return $xml;
        }
        $data = GeneralUtility::xml2array($xml);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return $xml;
        }

        try {
            $fieldTca = ['config' => $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? []];
            $identifier = $this->flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $row);
            $dataStructure = $this->flexFormTools->parseDataStructureByIdentifier($identifier);
        } catch (\Throwable $e) {
            $this->logger->warning('d-translate: could not resolve FlexForm data structure', [
                'table' => $table,
                'field' => $field,
                'exception' => $e->getMessage(),
            ]);
            return $xml;
        }

        $changed = false;
        foreach ($dataStructure['sheets'] ?? [] as $sheetKey => $sheetDefinition) {
            $elements = $sheetDefinition['ROOT']['el'] ?? [];
            if (!is_array($elements) || !isset($data['data'][$sheetKey]['lDEF']) || !is_array($data['data'][$sheetKey]['lDEF'])) {
                continue;
            }
            $this->walkElements($elements, $data['data'][$sheetKey]['lDEF'], $translate, $changed);
        }

        if (!$changed) {
            return $xml;
        }
        return $this->flexFormTools->flexArray2Xml($data, true);
    }

    /**
     * Recursively translate leaf input/text/RTE values, descending into
     * repeatable section containers.
     *
     * @param array<string, mixed> $dsElements   data-structure "el" for this level
     * @param array<string, mixed> $dataLevel    matching value level (by reference)
     * @param callable(string, bool): string $translate
     */
    private function walkElements(array $dsElements, array &$dataLevel, callable $translate, bool &$changed): void
    {
        foreach ($dsElements as $fieldName => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $config = $definition['config'] ?? null;
            if (is_array($config)) {
                if (!$this->isTranslatable($config)) {
                    continue;
                }
                $current = $dataLevel[$fieldName]['vDEF'] ?? null;
                if (!is_string($current) || trim($current) === '' || MathUtility::canBeInterpretedAsInteger($current)) {
                    continue;
                }
                try {
                    $dataLevel[$fieldName]['vDEF'] = $translate($current, !empty($config['enableRichtext']));
                    $changed = true;
                } catch (\Throwable $e) {
                    $this->logger->error('d-translate: FlexForm value translation failed', [
                        'flexField' => $fieldName,
                        'exception' => $e->getMessage(),
                    ]);
                }
                continue;
            }

            // Section: data holds numbered container instances, each
            // [containerType => ['el' => [ ...fields... ]]].
            if (isset($definition['el'], $dataLevel[$fieldName]['el'])
                && is_array($definition['el'])
                && is_array($dataLevel[$fieldName]['el'])
            ) {
                foreach ($dataLevel[$fieldName]['el'] as &$instance) {
                    if (!is_array($instance)) {
                        continue;
                    }
                    foreach ($instance as $containerType => &$containerData) {
                        $containerDs = $definition['el'][$containerType]['el'] ?? null;
                        if (is_array($containerDs) && isset($containerData['el']) && is_array($containerData['el'])) {
                            $this->walkElements($containerDs, $containerData['el'], $translate, $changed);
                        }
                    }
                    unset($containerData);
                }
                unset($instance);
            }
        }
    }

    /** @param array<string, mixed> $config */
    private function isTranslatable(array $config): bool
    {
        $type = (string)($config['type'] ?? '');
        if (!in_array($type, ['input', 'text'], true)) {
            return false;
        }
        $evals = array_filter(array_map('trim', explode(',', (string)($config['eval'] ?? ''))));
        return array_intersect($evals, ['int', 'double2', 'num', 'md5', 'password', 'is_in']) === [];
    }
}
