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

use Dreistein\DTranslate\Translation\ProcessingContext;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Records the table/uid of the record currently being localized into the
 * ProcessingContext, because the field-level translate hook does not receive
 * the table name. Only acts while the context is armed by a d-translate trigger.
 */
final class CommandMapHook
{
    public function __construct(
        private readonly ProcessingContext $context,
    ) {
    }

    /**
     * @param mixed $command e.g. 'localize', 'copy', 'move', 'delete'
     * @param mixed $id      source record uid
     * @param mixed $value   command argument (for localize: target sys_language_uid)
     */
    public function processCmdmap_preProcess(
        $command,
        string $table,
        $id,
        $value,
        DataHandler $dataHandler,
        $pasteUpdate = false,
        $pasteDatamap = []
    ): void {
        if (!$this->context->isArmed()) {
            return;
        }
        if ((string)$command === 'localize') {
            $this->context->setProcessing($table, (int)$id);
        }
    }
}
