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

namespace Dreistein\DTranslate\Backend\ItemsProc;

use Dreistein\DTranslate\Localization\Messages;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the profile's site_identifier select with the configured TYPO3
 * sites, so editors pick a site instead of typing its identifier. The empty
 * entry marks a profile as the default for sites without their own.
 */
final class SiteItemsProc
{
    /** @param array{items: array<int, array<int, mixed>>} $params */
    public function populate(array &$params): void
    {
        // Empty = the profile applies wherever no site-specific one exists.
        $params['items'][] = ['label' => Messages::db('tx_dtranslate_profile.site_identifier.all'), 'value' => ''];

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        foreach ($siteFinder->getAllSites() as $site) {
            $identifier = $site->getIdentifier();
            $base = (string)$site->getBase();
            $params['items'][] = [
                'label' => $base !== '' ? sprintf('%s (%s)', $identifier, $base) : $identifier,
                'value' => $identifier,
            ];
        }
    }
}
