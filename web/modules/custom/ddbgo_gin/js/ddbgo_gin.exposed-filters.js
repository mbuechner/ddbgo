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
        let pendingSelection = '';

        const selectedValues = () =>
          Array.from(tagFilter.selectedOptions, (option) => option.value)
            .sort()
            .join('\u0000');
        let submittedSelection = selectedValues();

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

            const selection = selectedValues();
            // Tagify's change event can precede its animated remove event.
            // Until remove updates the select, it still contains the old tag.
            // Ignore that unchanged state and duplicate events after submit.
            if (
              selection !== pendingSelection ||
              selection === submittedSelection
            ) {
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
