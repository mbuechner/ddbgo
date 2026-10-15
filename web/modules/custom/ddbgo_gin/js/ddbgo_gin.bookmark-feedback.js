/**
 * @file
 * Explicit dismissal for persistent bookmark feedback, without a time limit.
 *
 * Flag appends its message after AJAX has already attached behaviors. Decorate
 * that command once during attach (after Flag's script has loaded), preserving
 * its existing replacement, announcement, callback context and return value.
 * Only bookmark messages receive a button; no document-wide dismissal listener
 * or DOM observer is needed.
 */
(function (Drupal) {
  const enhanced = new WeakSet();
  const selector = '.flag-bookmark .js-flag-message';
  let decorated = false;

  function dismiss(message) {
    const wrapper = message.closest('.flag-bookmark');
    if (message.contains(document.activeElement) && wrapper) {
      const link = wrapper.querySelector('a[href]');
      // Do not leave keyboard focus on a removed button. If access changed and
      // Flag no longer renders a link, retain this position without a Tab stop.
      const target = link || wrapper;
      if (!link) {
        target.setAttribute('tabindex', '-1');
      }
      target.focus({ preventScroll: true });
    }
    message.remove();
  }

  function enhance(message) {
    if (!message || enhanced.has(message) || !message.textContent.trim()) {
      return;
    }
    enhanced.add(message);

    // Flag already announces the message through Drupal.announce(). The same
    // paragraph must not separately announce our newly inserted close button.
    message.removeAttribute('aria-live');
    const button = document.createElement('button');
    button.setAttribute('type', 'button');
    button.setAttribute('class', 'ddbgo-bookmark-message-dismiss');
    button.setAttribute('aria-label', Drupal.t('Lesezeichenmeldung schließen'));
    const icon = document.createElement('span');
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = '×';
    button.appendChild(icon);
    button.addEventListener('click', () => dismiss(message));
    message.appendChild(button);
    message.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !event.defaultPrevented) {
        event.preventDefault();
        event.stopPropagation();
        dismiss(message);
      }
    });
  }

  Drupal.behaviors.ddbgoBookmarkFeedback = {
    attach(context) {
      const commands = Drupal.AjaxCommands?.prototype;
      if (!decorated && typeof commands?.actionLinkFlash === 'function') {
        const original = commands.actionLinkFlash;
        commands.actionLinkFlash = function (ajax, response, status) {
          const result = original.apply(this, arguments);
          if (status === 'success' && response.message.length) {
            const wrapper = document.querySelector(response.selector);
            if (wrapper?.classList.contains('flag-bookmark')) {
              wrapper.querySelectorAll('.js-flag-message').forEach(enhance);
            }
          }
          return result;
        };
        decorated = true;
      }

      // Also support an existing message when this library is loaded by AJAX.
      context.querySelectorAll(selector).forEach(enhance);
      if (context.matches?.(selector)) {
        enhance(context);
      }
    },
  };
})(Drupal);
