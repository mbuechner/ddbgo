/**
 * @file
 * Keeps Tagify-based Views filters in sync with search results.
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

        const selectedValues = () =>
          Array.from(tagFilter.selectedOptions, (option) => option.value)
            .sort()
            .join('\u0000');
        let submittedSelection = selectedValues();

        tagFilter.addEventListener('change', () => {
          if (isSubmitQueued) {
            return;
          }

          isSubmitQueued = true;

          // Tagify emits multiple synchronous change events while updating
          // its hidden select. Submit only after that update cycle has ended.
          window.queueMicrotask(() => {
            isSubmitQueued = false;

            // Drupal creates Tagify on an input directly before this select.
            // Its native callbacks update one option at a time, and animated
            // removals can finish after another removal or addition. Reconcile
            // the entire select with the live widget before submitting it.
            const tagify = tagFilter.previousElementSibling?.__tagify;
            if (tagify) {
              const values = new Set(
                tagify.value.map((item) => String(item.value)),
              );
              Array.from(tagFilter.options).forEach((option) => {
                option.selected = values.has(option.value);
              });
            }

            const selection = selectedValues();
            if (selection === submittedSelection) {
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
              submittedSelection = selection;
              submit.click();
            }
          });
        });
      });
    },
  };
})(Drupal, once);
