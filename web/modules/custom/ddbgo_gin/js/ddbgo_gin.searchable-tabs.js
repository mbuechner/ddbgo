/**
 * @file
 * Makes inactive node tabs discoverable by the browser's find-in-page.
 *
 * Field Group hides inactive panes with display:none, which excludes their
 * content from Ctrl+F. In supporting browsers, use hidden="until-found" and
 * activate a matching section through Field Group's existing API. Do not
 * intercept the browser's search or move the user's keyboard focus.
 *
 * Native collapsed details already participate in find-in-page. Keep their
 * summary visible; only initialized horizontal tab panes need this extension.
 */

(function ($, Drupal, once) {
  // Older browsers retain Field Group's original visibility/validation logic.
  if (!('onbeforematch' in document.documentElement) || !Drupal.HorizontalTab) {
    return;
  }

  const paneSelector = '[data-horizontal-tabs-panes] > details';
  const contentSelector = '.node--view-mode-full, form.node-form';
  const marker = 'data-ddbgo-searchable-tab';

  function available(tab) {
    // Check the navigation item itself, not :visible: a nested group's whole
    // navigation can be inside an inactive parent. tabHide() deliberately
    // hides an entry and must not make its content searchable/revealable.
    const item = tab.item[0];
    return !tab.item.hasClass('horizontal-tab-hidden')
      && !item.hasAttribute('hidden') && item.style.display !== 'none';
  }

  function reveal(pane) {
    const ancestors = [];
    for (let current = pane; current; current = current.parentElement?.closest('details')) {
      ancestors.unshift(current);
    }
    // Opening a matching pane must never resurrect an intentionally hidden tab.
    if (ancestors.some((current) => {
      const tab = $(current).data('horizontalTab');
      return tab && !available(tab);
    })) {
      return;
    }
    ancestors.forEach((current) => {
      const tab = $(current).data('horizontalTab');
      if (tab) {
        // This API selects the tab, updates its hidden active-tab field and
        // screen-reader marker, but does not focus an input or tab link.
        tab.focus();
      }
      else {
        // Changing hidden in beforematch can stop native ancestor revelation.
        // Explicitly open any outer native details as well as the tab panes.
        current.open = true;
      }
    });
  }

  function normalize(parent) {
    if (!parent) {
      return;
    }
    Array.from(parent.children).forEach((pane) => {
      const tab = $(pane).data('horizontalTab');
      if (!tab || !pane.closest(contentSelector)) {
        return;
      }
      const owned = pane.hasAttribute(marker);
      if (!available(tab) || tab.item.hasClass('selected')) {
        // Remove only our own hiding state, leaving unrelated hidden intact.
        if (owned) {
          if (pane.getAttribute('hidden') === 'until-found') {
            pane.removeAttribute('hidden');
          }
          pane.removeAttribute(marker);
        }
        if (!available(tab)) {
          tab.details.hide();
        }
        return;
      }
      if (pane.hasAttribute('hidden') && (!owned || pane.getAttribute('hidden') !== 'until-found')) {
        return;
      }
      once('ddbgo-searchable-tab', [pane]).forEach((element) => {
        element.addEventListener('beforematch', (event) => {
          // beforematch bubbles. Each matching pane handles its own event and
          // selects its complete outer-to-inner chain exactly once.
          if (event.target === element) {
            reveal(element);
          }
        });
      });
      pane.setAttribute(marker, '');
      pane.setAttribute('hidden', 'until-found');
      pane.style.removeProperty('display');
      // Preserve horizontal-tab-hidden: validation and remembered tab links
      // use it even though an until-found element still has a layout box.
    });
  }

  // Append this script to Field Group's library after its implementation.
  // Wrapping its API covers click/keyboard switches, validation and tabHide,
  // without replacing the constructor or copying contrib code into a patch.
  ['focus', 'tabHide'].forEach((method) => {
    const original = Drupal.HorizontalTab.prototype[method];
    Drupal.HorizontalTab.prototype[method] = function (...args) {
      const result = original.apply(this, args);
      normalize(this.details[0].parentElement);
      return result;
    };
  });

  Drupal.behaviors.ddbgoSearchableTabs = {
    attach(context) {
      const parents = new Set();
      const collect = (pane) => parents.add(pane.parentElement);
      context.querySelectorAll(paneSelector).forEach(collect);
      // Drupal can reattach just a pane or an AJAX widget inside that pane.
      const enclosing = context.closest?.(paneSelector);
      if (enclosing) {
        collect(enclosing);
      }
      parents.forEach(normalize);
    },
  };
})(jQuery, Drupal, once);
