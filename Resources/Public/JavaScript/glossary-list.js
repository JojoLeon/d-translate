/**
 * d-translate: glossary list of the backend module.
 *
 * Two conveniences on top of a plain GET form: a changed filter submits on its
 * own, and a delete link asks first. Both degrade — without JavaScript the
 * Filter button and a direct delete still work.
 */
(function () {
    function init() {
        document.querySelectorAll('[data-glossary-autosubmit]').forEach(function (select) {
            select.addEventListener('change', function () {
                var form = select.closest('form');
                if (!form) {
                    return;
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });

        var dialog = document.querySelector('[data-glossary-confirm]');
        var text = dialog ? dialog.querySelector('[data-glossary-confirm-text]') : null;
        var proceed = dialog ? dialog.querySelector('[data-glossary-confirm-continue]') : null;
        var native = dialog && text && proceed && typeof dialog.showModal === 'function';

        document.querySelectorAll('[data-glossary-delete]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var question = link.getAttribute('data-confirm') || '';
                if (!native) {
                    if (!window.confirm(question)) {
                        event.preventDefault();
                    }
                    return;
                }
                event.preventDefault();
                text.textContent = question;
                proceed.setAttribute('href', link.getAttribute('href'));
                dialog.showModal();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
