/**
 * @file
 * Keeps Tagify-based Views filters in sync with their result lists.
 */

(function (Drupal, once) {
  Drupal.behaviors.ddbgoTagExposedFilters = {
    attach(context) {
      once(
        'ddbgo-tag-auto-submit',
        '[data-ddbgo-tag-auto-submit]',
        context,
      ).forEach((tagFilter) => {
          let isSubmitQueued = false;
          let pendingSelection = '';

          const selectedValues = () =>
            Array.from(tagFilter.selectedOptions, (option) => option.value)
              .sort()
              .join('\u0000');

        tagFilter.addEventListener('change', () => {
            pendingSelection = selectedValues();

            if (isSubmitQueued) {
              return;
            }

            isSubmitQueued = true;

            // Tagify emits multiple synchronous change events while updating
            // its hidden select. Submit only after that update cycle has ended.
            window.queueMicrotask(() => {
              isSubmitQueued = false;

              if (selectedValues() !== pendingSelection) {
                return;
              }

            const form = tagFilter.closest('form');
            if (!form) {
              return;
            }

            const submit = form.querySelector(
              '[data-ddbgo-tag-auto-submit-click]',
            );
            if (submit && !submit.disabled) {
              submit.click();
            }
          });
        });
      });
    },
  };
})(Drupal, once);
