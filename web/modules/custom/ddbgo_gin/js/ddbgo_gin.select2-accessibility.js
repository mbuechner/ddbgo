/**
 * @file
 * Give Select2's focus targets the original field's name and description,
 * and retain valid native semantics for its inline textarea.
 *
 * Select2 hides the native select. Its replacement therefore needs its own
 * label/help references; a label's `for` still points only to the hidden select.
 * This correction changes text associations and invalid textarea markup,
 * not keyboard handling, selection, popup roles or aria-controls.
 */

(function ($, Drupal, once) {
  let labelSequence = 0;

  /**
   * Reuse the native field's naming precedence without copying label text.
   *
   * IDs added to labels survive repeated attach and stay unique after AJAX.
   * Explicit ARIA names (including invisible labels) retain their precedence.
   */
  function fieldName(select) {
    for (const attribute of ['aria-labelledby', 'aria-label']) {
      const value = select.getAttribute(attribute)?.trim();
      if (value) {
        return { attribute, value };
      }
    }

    const ids = Array.from(select.labels, (label) => {
      if (!label.id) {
        let id;
        do {
          id = `ddbgo-select2-label-${++labelSequence}`;
        } while (select.ownerDocument.getElementById(id));
        label.id = id;
      }
      return label.id;
    });
    return ids.length ? { attribute: 'aria-labelledby', value: ids.join(' ') } : null;
  }

  /**
   * Merge ID references, retaining Select2's references to selected items.
   *
   * The help text may be a hidden Gin tooltip: its existing ID must be reused,
   * so the description works without opening the help button first.
   */
  function describe(element, ...references) {
    const ids = references.filter(Boolean).join(' ').trim().split(/\s+/).filter(Boolean);
    if (ids.length) {
      element.setAttribute('aria-describedby', [...new Set(ids)].join(' '));
    }
  }

  Drupal.behaviors.ddbgoSelect2Accessibility = {
    attach(context) {
      // This dependency registers before contrib's behavior. Defer until all
      // synchronous behaviors finish, including Select2 initialization. Its
      // select2-init event fires BEFORE the replacement elements exist.
      window.queueMicrotask(() => {
        const selects = Array.from(context.querySelectorAll('select.select2-widget'));
        // Drupal AJAX may supply the field itself as the attachment context.
        if (context.matches?.('select.select2-widget')) {
          selects.unshift(context);
        }

        selects.forEach((select) => {
          const instance = $(select).data('select2');
          const selection = instance?.$selection?.[0];
          if (!selection) {
            return;
          }

          // Mark the generated element, not the select: destroy/reinitialize
          // creates a new focus target that needs the correction again.
          once('ddbgo-select2-accessibility', selection).forEach(() => {
            const name = fieldName(select);
            const description = select.getAttribute('aria-describedby');
            const inlineSearch = instance.selection?.$search?.[0];
            const targets = [selection, instance.dropdown?.$search?.[0], inlineSearch];

            // Select2 4.1 gives its inline textarea a searchbox role and input
            // type. Neither is allowed here; retain its native textbox role.
            // Only correct this known markup, leaving actual search inputs
            // and any different role supplied by a future adapter untouched.
            if (inlineSearch?.tagName === 'TEXTAREA' && inlineSearch.getAttribute('role') === 'searchbox') {
              inlineSearch.removeAttribute('role');
              if (inlineSearch.getAttribute('type') === 'search') {
                inlineSearch.removeAttribute('type');
              }
            }

            // Both search adapters exist at initialization, even while the
            // dropdown is detached. Name them before Select2 first focuses
            // them; select2:open would run after that focus announcement.
            targets.filter(Boolean).forEach((target) => {
              if (name) {
                target.removeAttribute('aria-labelledby');
                target.removeAttribute('aria-label');
                target.setAttribute(name.attribute, name.value);
              }
              describe(target, target.getAttribute('aria-describedby'), description);
            });
          });
        });
      });
    },
  };
})(jQuery, Drupal, once);
