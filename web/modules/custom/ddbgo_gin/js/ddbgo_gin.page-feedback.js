/**
 * @file
 * Make the outcome of a full page request apparent in Gin's existing messages.
 *
 * Live roles on HTML received with a new page do not reliably announce its
 * initial contents. Focus the server-rendered error summary, connecting its
 * content as a description alongside Gin's existing heading; announce other
 * messages through Drupal's initially empty live region. Error focus and its
 * announcement are mutually exclusive. There is no need to copy Gin's template
 * just to add a content ID.
 *
 * The explicit marker belongs only to server-rendered messages. AJAX messages
 * already have Drupal's announcement handling and must not be read here again.
 */
(function (Drupal) {
  let attached = false;
  let errorSequence = 0;

  Drupal.behaviors.ddbgoPageFeedback = {
    attach(context) {
      // Remember the initial attachment even when it has no messages. Later
      // AJAX attachments or a restored document must never repeat feedback.
      if (context !== document || attached) {
        return;
      }
      attached = true;
      const messages = Array.from(document.querySelectorAll('.messages[data-ddbgo-page-messages]'));
      if (!messages.length) {
        return;
      }

      // Let the other initial behaviors create Drupal's live region and finish
      // their own focus handling before deciding whether focus is still free.
      window.queueMicrotask(() => {
        const connected = messages.filter((message) => message.isConnected);
        const error = connected.find((message) => message.classList.contains('messages--error'));
        if (error) {
          const content = error.querySelector('.messages__content');
          if (!content?.isConnected || !content.textContent.trim()) {
            return;
          }
          const active = document.activeElement;
          // Respect autofocus and any control the user has already reached.
          // An already focused summary also needs no second focus event.
          if (!active || active === document.body || active === document.documentElement) {
            if (!content.id) {
              let id;
              do {
                id = `ddbgo-page-error-${++errorSequence}`;
              } while (document.getElementById(id));
              content.id = id;
            }
            const ids = (error.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
            error.setAttribute('aria-describedby', [...new Set([...ids, content.id])].join(' '));
            error.focus();
          }
          else if (active !== error) {
            // A focused input must stay usable without concealing the failed
            // request. Announce instead of moving focus; never do both.
            Drupal.announce(Drupal.checkPlain(content.textContent.trim()), 'assertive');
          }
          return;
        }

        const notices = connected.filter((message) => message.classList.contains('messages--status')
          || message.classList.contains('messages--warning'));
        const text = notices.map((message) => message.querySelector('.messages__content')?.textContent.trim())
          .filter(Boolean).join('\n');
        if (text) {
          // Drupal.announce uses innerHTML. Escape plain text again so names or
          // other user-provided message text cannot become live-region markup.
          const priority = notices.some((message) => message.classList.contains('messages--warning'))
            ? 'assertive' : 'polite';
          Drupal.announce(Drupal.checkPlain(text), priority);
        }
      });
    },
  };
})(Drupal);
