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

use TYPO3\CMS\Core\DataHandling\Model\RecordStateFactory;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds the slug core would generate for a record — from the generator
 * fields the record carries, translated already — and makes it unique in the
 * scope the field's TCA demands (per site for pages, per pid or per table for
 * the rest). A slug is not prose: it is regenerated, never translated.
 */
final class SlugGenerator
{
    /**
     * The unique slug for $record: build() followed by unique().
     *
     * @param array<string, mixed> $record full record
     */
    public function generate(string $table, string $field, array $record): string
    {
        return $this->unique($table, $field, $this->build($table, $field, $record), $record);
    }

    /**
     * The slug as SlugHelper generates it, not yet checked for uniqueness.
     * Without the parent page prefix the result is the record's own part only
     * (`/title`), for a caller that supplies the prefix itself.
     *
     * @param array<string, mixed> $record full record
     */
    public function build(string $table, string $field, array $record, bool $prefixParentPageSlug = true): string
    {
        $config = $this->fieldConfig($table, $field);
        if (!$prefixParentPageSlug) {
            $config['generatorOptions']['prefixParentPageSlug'] = false;
        }
        return $this->helper($table, $field, $config)->generate($record, (int)($record['pid'] ?? 0));
    }

    /**
     * $slug made unique where the field's eval wants it to be; a slug that is
     * unique already comes back as it is.
     *
     * @param array<string, mixed> $record the record the slug belongs to
     */
    public function unique(string $table, string $field, string $slug, array $record): string
    {
        $config = $this->fieldConfig($table, $field);
        $helper = $this->helper($table, $field, $config);
        $pid = (int)($record['pid'] ?? 0);
        $state = RecordStateFactory::forName($table)->fromArray($record, $pid, (int)($record['uid'] ?? 0));

        $evals = GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true);
        if (in_array('uniqueInSite', $evals, true)) {
            return $helper->isUniqueInSite($slug, $state) ? $slug : $helper->buildSlugForUniqueInSite($slug, $state);
        }
        if (in_array('uniqueInPid', $evals, true)) {
            return $helper->isUniqueInPid($slug, $state) ? $slug : $helper->buildSlugForUniqueInPid($slug, $state);
        }
        if (in_array('unique', $evals, true)) {
            return $helper->isUniqueInTable($slug, $state) ? $slug : $helper->buildSlugForUniqueInTable($slug, $state);
        }
        return $slug;
    }

    /**
     * $slug cleaned the way SlugHelper cleans every generated slug.
     */
    public function sanitize(string $table, string $field, string $slug): string
    {
        return $this->helper($table, $field, $this->fieldConfig($table, $field))->sanitize($slug);
    }

    /** @return array<string, mixed> */
    private function fieldConfig(string $table, string $field): array
    {
        return $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? [];
    }

    /** @param array<string, mixed> $config */
    private function helper(string $table, string $field, array $config): SlugHelper
    {
        return GeneralUtility::makeInstance(SlugHelper::class, $table, $field, $config);
    }
}
