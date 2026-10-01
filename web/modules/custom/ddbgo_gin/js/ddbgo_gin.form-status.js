/**
 * @file
 * Announce AJAX waiting states and connect Unique Field AJAX notices to fields.
 *
 * Form API supplies data-ddbgo-form-status only on the four Gin node forms.
 * Use Drupal's existing, initially empty live region rather than making whole
 * form sections live. Initial rendering and repeated behavior attachment are
 * deliberately silent. Validation, submission and focus remain Drupal's job.
 */
(function (Drupal) {
  const observed = new WeakSet();
  const pending = new WeakMap();
  const formSelector = 'form[data-ddbgo-form-status]';
  const noticePrefix = 'ddbgo-unique-notice-';
  let noticeSequence = 0;

  /** Unique Field AJAX puts its notice outside the input's form-item wrapper. */
  function noticeIn(wrapper) {
    return wrapper?.querySelector(':scope > div.warning, :scope > div.error');
  }

  /**
   * Reuse a notice's ID and preserve all existing help/description references.
   * Remove only our own references when the warning disappears or is replaced.
   * This also works on initial server output, before any request takes place.
   */
  function describeNotices(form) {
    form.querySelectorAll('[id^="unique-"]').forEach((wrapper) => {
      const input = wrapper.querySelector('input:not([type="hidden"])');
      if (!input) {
        return;
      }
      const notice = noticeIn(wrapper);
      if (notice && !notice.id) {
        let id;
        do {
          id = `${noticePrefix}${++noticeSequence}`;
        } while (document.getElementById(id));
        notice.id = id;
      }
      const ids = (input.getAttribute('aria-describedby') || '').split(/\s+/)
        .filter((id) => id && (!id.startsWith(noticePrefix) || id === notice?.id));
      if (notice) {
        ids.push(notice.id);
      }
      if (ids.length) {
        input.setAttribute('aria-describedby', [...new Set(ids)].join(' '));
      }
      else {
        input.removeAttribute('aria-describedby');
      }
    });
  }

  /** Get context from the original trigger, even if AJAX later replaces it. */
  function triggerLabel(element) {
    return (element.getAttribute('aria-label')
      || Array.from(element.labels || [], (label) => label.textContent.trim()).join(' ')
      || (element.matches('button, input[type="submit"]') ? element.value || element.textContent : '')
      || Drupal.t('Formular')).trim();
  }

  /** Finish this request without touching its controls, progress UI or focus. */
  function clear(ajax, state = pending.get(ajax)) {
    if (state) {
      window.clearTimeout(state.timer);
      if (pending.get(ajax) === state) {
        pending.delete(ajax);
      }
    }
  }

  function observe(ajax) {
    const form = ajax?.element?.form || ajax?.$form?.[0];
    if (!form?.matches(formSelector) || observed.has(ajax)
      || !['throbber', 'fullscreen'].includes(ajax.progress?.type)) {
      return;
    }
    observed.add(ajax);

    // Decorate this instance, never Drupal.Ajax.prototype. Core options call
    // these methods dynamically; its success method runs after token checking
    // and resolves after response commands, refocus and behavior reattachment.
    const beforeSend = ajax.beforeSend;
    ajax.beforeSend = function (...args) {
      const result = beforeSend.apply(this, args);
      if (result !== false) {
        clear(ajax);
        const state = {
          form,
          label: triggerLabel(ajax.element),
          uniqueId: ajax.element.closest('[id^="unique-"]')?.id,
        };
        pending.set(ajax, state);
        // Avoid "please wait" chatter for requests that have already finished.
        state.timer = window.setTimeout(() => {
          if (pending.get(ajax) === state && form.isConnected) {
            Drupal.announce(Drupal.t('@field: Bitte warten.', { '@field': state.label }));
          }
        }, 500);
      }
      return result;
    };

    const success = ajax.success;
    ajax.success = function (...args) {
      const state = pending.get(ajax);
      let result;
      try {
        result = success.apply(this, args);
      }
      catch (error) {
        clear(ajax, state);
        throw error;
      }
      return Promise.resolve(result).then((value) => {
        // A stale response or a detached form must not produce an announcement.
        if (!state || pending.get(ajax) !== state) {
          return value;
        }
        clear(ajax, state);
        if (!form.isConnected) {
          return value;
        }
        describeNotices(form);
        const wrapper = state.uniqueId && document.getElementById(state.uniqueId);
        const notice = wrapper && form.contains(wrapper) && noticeIn(wrapper);
        if (notice) {
          // Drupal.announce writes HTML internally. Drupal.t's @ placeholders
          // escape both label and plain notice text, including user-entered names.
          Drupal.announce(Drupal.t('@field: @message', {
            '@field': state.label,
            '@message': notice.textContent.trim(),
          }));
        }
        else if (!Object.values(args[0] || {}).some((command) =>
          command?.command === 'announce' || command?.command === 'message')) {
          // End the waiting state without claiming that data was saved or that
          // validation passed. Core may log a command error and resolve anyway.
          Drupal.announce(Drupal.t('@field: Ladevorgang beendet.', { '@field': state.label }));
        }
        return value;
      }, (error) => {
        clear(ajax, state);
        throw error;
      });
    };

    // Core's options.error also runs for aborts; ajax.error additionally handles
    // response-verification failures. Preserve the existing submit guard and
    // Core's assertive error announcement/exceptions; do not announce them twice.
    for (const [target, method] of [[ajax.options, 'error'], [ajax, 'error']]) {
      const original = target[method];
      target[method] = function (...args) {
        clear(ajax);
        return original.apply(this, args);
      };
    }
  }

  Drupal.behaviors.ddbgoFormStatus = {
    attach() {
      document.querySelectorAll(formSelector).forEach(describeNotices);
      // Allow behaviors from AJAX-loaded contrib libraries to create instances
      // before observing them. WeakSet prevents wrappers on repeated attachment.
      window.queueMicrotask(() => (Drupal.ajax.instances || []).forEach(observe));
    },
  };
})(Drupal);
