/**
 * d-translate: provider defaults for the LLM configuration record.
 *
 * Picking a provider type fills "API base URL" with that provider's endpoint —
 * but only while the field is still empty, so a hand-entered URL (proxy, Azure,
 * self-hosted gateway) is never overwritten. The placeholder always follows the
 * selected provider.
 */
export default function initialize(config) {
    const select = document.querySelector('select[name="' + config.typeField + '"]');
    // FormEngine renders a visible field plus a hidden field carrying the value.
    const input = document.querySelector('[data-formengine-input-name="' + config.endpointField + '"]');
    if (select === null || input === null) {
        return;
    }
    const hidden = document.querySelector('input[type="hidden"][name="' + config.endpointField + '"]');
    const defaults = config.defaults || {};

    const isEmpty = function () {
        if (input.value.trim() !== '') {
            return false;
        }
        return hidden === null || hidden.value.trim() === '';
    };

    const apply = function (fillValue) {
        const endpoint = defaults[select.value] || '';
        input.placeholder = endpoint;
        if (!fillValue || endpoint === '' || !isEmpty()) {
            return;
        }
        input.value = endpoint;
        // Lets FormEngine copy the value into the hidden field and mark the form dirty.
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    // On load only align the placeholder — filling here would dirty the form
    // for records the editor merely opened.
    apply(false);
    select.addEventListener('change', function () {
        apply(true);
    });
}
