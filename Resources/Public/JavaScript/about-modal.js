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

/**
 * About dialog (company + licence) — vanilla JS, CSP-safe.
 * Trigger: [data-about-open], dialog: <dialog data-about-dialog>,
 * close: [data-about-close], a click on the backdrop, or ESC (native).
 */
(function () {
    'use strict';

    var dialog = document.querySelector('[data-about-dialog]');
    if (!dialog || typeof dialog.showModal !== 'function') { return; }

    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-about-open]')) {
            event.preventDefault();
            dialog.showModal();
            return;
        }
        if (event.target.closest('[data-about-close]')) {
            dialog.close();
            return;
        }
        // Klick auf den Backdrop trifft das <dialog> selbst (Inhalt liegt im Wrapper)
        if (event.target === dialog && dialog.open) {
            dialog.close();
        }
    });
})();
