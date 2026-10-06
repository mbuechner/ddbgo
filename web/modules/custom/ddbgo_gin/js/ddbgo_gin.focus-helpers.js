/**
 * @file
 * Uses valid data-* markers for Gin's existing sticky-action focus helpers.
 *
 * Loaded after gin/more_actions. Replace only moveFocus(), retaining Gin's
 * action dispatch, AJAX handling and menu behavior. This mirrors Gin 5.0's
 * focus transfer; review it alongside more_actions.js when updating the theme.
 */

(function (Drupal, once) {
  Drupal.ginStickyFormActions.moveFocus = function (newParent, form) {
    once(
      'ginMoveFocusToStickyBar',
      '[data-gin-move-focus-to-sticky-bar]',
      form,
    ).forEach((helper) => {
      helper.addEventListener('focus', (event) => {
        const firstAction = newParent.querySelector(
          'button, input, select, textarea, .action-link',
        );
        if (!firstAction) {
          return;
        }
        event.preventDefault();
        firstAction.focus();

        // Keep the return helper after the actions, as in Gin. Build native
        // elements so the dynamic markup also uses the valid data-* marker.
        const wrapper = document.createElement('div');
        wrapper.style.display = 'contents';
        const returnLink = document.createElement('a');
        returnLink.href = '#';
        returnLink.className = 'visually-hidden';
        returnLink.setAttribute('role', 'button');
        returnLink.setAttribute('data-gin-move-focus-to-end-of-form', '');
        returnLink.textContent = Drupal.t('Moves focus back to form');
        wrapper.appendChild(returnLink);
        newParent.appendChild(wrapper);

        returnLink.addEventListener('focus', (returnEvent) => {
          returnEvent.preventDefault();
          wrapper.remove();
          const next = helper.nextElementSibling || helper.parentNode.nextElementSibling;
          next?.focus();
        });
      });
    });
  };
})(Drupal, once);
