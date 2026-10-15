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

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Populates the profile's site checkbox list with the configured TYPO3
 * sites, so editors tick sites instead of typing identifiers. A profile with
 * no site ticked is the default for every site without one of its own.
 */
final class SiteItemsProc
{
    /** @param array{items: array<int, array<int, mixed>>} $params */
    public function populate(array &$params): void
    {
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
