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

use Dreistein\DTranslate\Exception\LlmRequestException;
use Dreistein\DTranslate\Service\FieldPolicy;
use Dreistein\DTranslate\Service\TranslationService;
use Dreistein\DTranslate\Translation\ProcessingContext;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;

/**
 * DataHandler `processTranslateToClass` hook. Fires once per translatable field
 * during a "localize" command, with the source value by reference. We replace
 * it with the LLM translation and DataHandler writes it into the new record.
 *
 * Stays completely inert unless the ProcessingContext has been armed — the
 * opt-in gate that keeps normal editor "Translate" actions away from the LLM.
 * Field selection is refined by FieldPolicy (TCA-aware) and the profile's
 * table allowlist.
 */
final class TranslateFieldHook
{
    public function __construct(
        private readonly ProcessingContext $context,
        private readonly TranslationService $translationService,
        private readonly FieldPolicy $fieldPolicy,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param mixed  $content        source field value, written back by reference
     * @param array  $languageRecord target site-language record
     * @param string $columnName     processed field name (since TYPO3 v11)
     */
    public function processTranslateTo_copyAction(
        &$content,
        array $languageRecord,
        DataHandler $dataHandler,
        string $columnName = ''
    ): void {
        $profile = $this->context->getProfile();
        $table = $this->context->getTable();
        if (!$this->context->isArmed() || $profile === null || $table === null) {
            return;
        }
        if (!$profile->allowsTable($table)) {
            return;
        }
        $value = (string)$content;
        if (trim($value) === '' || MathUtility::canBeInterpretedAsInteger($value)) {
            return;
        }
        // Only translate real columns of the record currently being localized
        // (the context table). Fields that do not resolve there belong to an
        // inline child of another table (IRRE/ContentBlocks/FAL reference) which
        // is handled — from its clean source value — by the InlineTranslator.
        // Letting the hook touch those caused double work and "[Translate to …]"
        // prefixes on prefix-behaviour child fields.
        $known = $columnName !== '' && $this->fieldPolicy->isKnownField($table, $columnName);
        if (!$known || !$this->fieldPolicy->shouldTranslate($table, $columnName)) {
            return;
        }
        $containsHtml = $this->fieldPolicy->format($table, $columnName) === FieldPolicy::FORMAT_HTML;

        try {
            $content = $this->translationService->translateField(
                $value,
                $profile,
                $this->context->getTargetLanguage(),
                $this->context->getGlossaryBlock(),
                $this->context->getPageContext(),
                $containsHtml,
            );
        } catch (LlmRequestException $e) {
            // A misconfiguration (wrong key, unknown model) will hit every
            // remaining field the same way — abort so the module can show it
            // instead of quietly producing untranslated records.
            if ($e->fatal) {
                throw $e;
            }
            $this->reportFailure($table, $columnName, $e);
        } catch (\Throwable $e) {
            // Never break the localize operation — keep the source value on failure.
            $this->reportFailure($table, $columnName, $e);
        }
    }

    private function reportFailure(string $table, string $columnName, \Throwable $e): void
    {
        $this->logger->error('d-translate field translation failed', [
            'table' => $table,
            'field' => $columnName,
            'exception' => $e->getMessage(),
        ]);
        $this->context->addError(sprintf('%s.%s: %s', $table, $columnName, $e->getMessage()));
    }
}
