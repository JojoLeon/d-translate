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

use Dreistein\DTranslate\Localization\Messages;
use Dreistein\DTranslate\Service\GlossaryStore;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * The "Glossary" field of a translation profile: a count and a button into
 * the module's glossary list. Replaces the inline field that rendered every
 * entry inside the form — with thousands of entries the form took half a
 * minute to open. A record of this field is never written by FormEngine.
 */
final class GlossaryManagerElement extends AbstractFormElement
{
    public function render(): array
    {
        $result = $this->initializeResultArray();
        $uid = $this->data['databaseRow']['uid'] ?? 0;

        if (!MathUtility::canBeInterpretedAsInteger($uid) || (int)$uid <= 0) {
            // A new profile has no uid for entries to point at yet.
            $html = '<p class="text-muted mb-0">' . htmlspecialchars(Messages::db('tx_dtranslate_profile.glossary.saveFirst')) . '</p>';
        } else {
            $store = GeneralUtility::makeInstance(GlossaryStore::class, GeneralUtility::makeInstance(ConnectionPool::class));
            // The profile's own entries plus the ones shared by every profile —
            // both reach the prompt, so both count.
            $count = $store->count((int)$uid) + $store->count(GlossaryStore::SHARED_PROFILE);
            $url = (string)GeneralUtility::makeInstance(UriBuilder::class)
                ->buildUriFromRoute('dtranslate.glossary', ['profile' => (int)$uid]);
            $html = '<p>' . htmlspecialchars(Messages::db('tx_dtranslate_profile.glossary.hint')) . '</p>'
                . '<a class="btn btn-default" href="' . htmlspecialchars($url) . '">'
                . htmlspecialchars(sprintf(Messages::db('tx_dtranslate_profile.glossary.manage'), $count))
                . '</a>';
        }

        $result['html'] = '<div class="formengine-field-item t3js-formengine-field-item">' . $html . '</div>';
        return $result;
    }
}
