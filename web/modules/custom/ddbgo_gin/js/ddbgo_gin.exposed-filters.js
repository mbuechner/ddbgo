/**
 * @file
 * Adds search help and keeps Tagify-based Views filters in sync with results.
 */

(function (Drupal, once) {
  Drupal.behaviors.ddbgoExposedFilterHelp = {
    attach(context) {
      once(
        'ddbgo-exposed-filter-help',
        '.views-exposed-form .help-icon__description-toggle',
        context,
      ).forEach((button) => {
        const container = button.closest('.help-icon__description-container');
        const description = container.querySelector('.form-item__description');
        if (!description) {
          return;
        }

        // Keep Gin's click/keyboard toggle and add the same text as a native
        // mouseover hint. The button must never submit the exposed search form.
        button.type = 'button';
        button.title = description.textContent.trim();
        const label = container.querySelector('label');
        button.setAttribute('aria-label', Drupal.t('Hilfe zu @label', {
          '@label': label ? label.textContent.trim() : Drupal.t('Filter'),
        }));
        if (description.id) {
          button.setAttribute('aria-controls', description.id);
        }
      });
    },
  };

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
