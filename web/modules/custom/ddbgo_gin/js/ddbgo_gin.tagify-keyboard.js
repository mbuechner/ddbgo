/**
 * @file
 * Keep Tab navigation in Tagify selects free of selection side effects.
 */

(function (Drupal, once) {
  Drupal.behaviors.ddbgoTagifyKeyboard = {
    attach(context) {
      // This library is a dependency of Tagify's Drupal integration. Wait until
      // its behaviors have created the instances, also after an AJAX rebuild.
      window.queueMicrotask(() => {
        context.querySelectorAll('input.tagify-select-widget').forEach((input) => {
          const tagify = input.__tagify;
          if (!tagify) {
            return;
          }

          once('ddbgo-tagify-keyboard', tagify.DOM.scope).forEach((scope) => {
            // Shift+Tab reaches the selected tags' remove buttons. Tagify must
            // not immediately send their focus back into the typing area.
            tagify.settings.focusInputOnRemove = false;
            // Leaving a search string must not turn it into a selected tag.
            tagify.settings.addTagOnBlur = false;
            tagify.settings.addTagOn = tagify.settings.addTagOn.filter(
              (key) => key !== 'tab' && key !== 'blur',
            );

            scope.addEventListener('keydown', (event) => {
              if (event.key !== 'Tab') {
                return;
              }

              // Tagify's dropdown handles Tab independently of addTagOn.
              // Intercept it before the input/window handlers, in both
              // directions. Do not preventDefault or move focus ourselves.
              tagify.dropdown.hide();
              event.stopPropagation();
            }, true);
          });
        });
      });
    },
  };
})(Drupal, once);
