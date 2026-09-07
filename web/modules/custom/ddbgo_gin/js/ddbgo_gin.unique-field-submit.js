/**
 * @file
 * Defers normal form submission until Unique Field AJAX has finished.
 *
 * Drupal disables an AJAX trigger while checking it. Disabled fields are not
 * submitted, so saving during a duplicate check can silently drop their values.
 * Keep the installed validation behavior; coordinate only the final submission.
 */
(function (Drupal, once, drupalSettings) {
  const pending = new WeakMap();
  const observedRequests = new WeakSet();

  /** Find requests belonging to the form, including detached trigger elements. */
  const requestsFor = (form) => (Drupal.ajax.instances || []).filter((ajax) =>
    ajax && (ajax.element?.form === form || ajax.$form?.[0] === form));

  const hasUniqueFields = (form) =>
    (drupalSettings.unique_field_ajax || []).some(({ id }) => form.querySelector(id));

  /** Release the queued action without changing any input's disabled state. */
  function release(form, state) {
    if (state.submitter) {
      if (state.ariaDisabled === null) {
        state.submitter.removeAttribute('aria-disabled');
      }
      else {
        state.submitter.setAttribute('aria-disabled', state.ariaDisabled);
      }
    }
    pending.delete(form);
  }

  function cancel(form) {
    const state = pending.get(form);
    if (state) {
      release(form, state);
      Drupal.announce(Drupal.t('Die Prüfung wurde nicht abgeschlossen. Bitte erneut speichern.'));
    }
  }

  /**
   * Wait for ajaxing, not just the network response: Drupal clears that flag
   * after all response commands, field replacements and behaviors have run.
   */
  function resume(form, state) {
    if (pending.get(form) !== state) {
      return;
    }
    if (!form.isConnected) {
      release(form, state);
      return;
    }
    // Drupal may remove detached instances from its global registry while
    // response commands are still running. Retain those seen by this save.
    requestsFor(form).forEach((ajax) => state.requests.add(ajax));
    if (Array.from(state.requests).some((ajax) => ajax.ajaxing)) {
      window.setTimeout(() => resume(form, state), 50);
      return;
    }

    // A response can replace form controls. Never fall back to another action
    // when the original submit button has disappeared or remains disabled.
    let submitter = state.submitter;
    if (submitter && submitter.form !== form) {
      submitter = Array.from(form.elements).find((element) =>
        state.selector
          ? element.getAttribute('data-drupal-selector') === state.selector
          : state.id && element.id === state.id);
    }
    if ((state.submitter && !submitter) || submitter?.disabled) {
      cancel(form);
      return;
    }

    state.resuming = true;
    try {
      // Preserve the submitter's name/value, native required-field validation,
      // and Drupal's normal submit handlers. form.submit() would bypass these.
      if (submitter) {
        form.requestSubmit(submitter);
      }
      else {
        form.requestSubmit();
      }
    }
    finally {
      release(form, state);
    }
  }

  Drupal.behaviors.ddbgoUniqueFieldSubmit = {
    attach() {
      // Observe each request instance once, including instances added by AJAX.
      // Wrap only callbacks on forms using Unique Field AJAX, never prototypes.
      (Drupal.ajax.instances || []).forEach((ajax) => {
        const form = ajax?.element?.form || ajax?.$form?.[0];
        if (!form || !hasUniqueFields(form) || observedRequests.has(ajax)) {
          return;
        }
        observedRequests.add(ajax);
        const originalError = ajax.options.error;
        ajax.options.error = function (...args) {
          cancel(form);
          return originalError.apply(this, args);
        };
      });

      once('ddbgo-unique-field-submit', 'html').forEach(() => {
        document.addEventListener('submit', (event) => {
          const form = event.target;
          const state = pending.get(form);
          if (state?.resuming || (!state && !hasUniqueFields(form))) {
            return;
          }

          // Capture before Drupal's formSingleSubmit records the form values.
          // Repeated clicks while waiting must produce only one submission.
          event.preventDefault();
          event.stopImmediatePropagation();
          if (state) {
            return;
          }
          const submitter = event.submitter;
          const next = {
            submitter,
            requests: new Set(requestsFor(form)),
            selector: submitter?.getAttribute('data-drupal-selector'),
            id: submitter?.id,
            ariaDisabled: submitter?.getAttribute('aria-disabled') ?? null,
            resuming: false,
          };
          pending.set(form, next);
          submitter?.setAttribute('aria-disabled', 'true');

          // Leaving the input schedules finishedinput with setTimeout(0).
          // Always defer one task, even if ajaxing is still false at this point.
          window.setTimeout(() => resume(form, next), 0);
        }, true);

        document.addEventListener('reset', (event) => {
          const state = pending.get(event.target);
          if (state) {
            release(event.target, state);
          }
        }, true);
      });
    },
  };
})(Drupal, once, drupalSettings);
