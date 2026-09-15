/**
 * @file
 * Carries the selected section between a node's view and edit pages.
 *
 * PHP supplies the current node's view/edit URLs in ddbgoNodeTabs.links. This
 * behavior adds a fragment such as #ddbgo-tab=edit-group-informationen to those
 * links, then restores that section after Field Group initializes on arrival.
 * The fragment travels with the link, including when opened in a new tab;
 * cookies or browser storage could mix selections from separate open records.
 *
 * Field Group owns tab visibility, selection and validation. Use its tab API
 * to activate a section; native details are the fallback below its breakpoint.
 */

(function ($, Drupal, once, drupalSettings) {
  const paneSelector = '[data-horizontal-tabs-panes] > details';
  const fragmentPrefix = '#ddbgo-tab=';

  // Normalize both directions to one key without depending on translated labels
  // or tab positions. These pairs come from the node view/form display configs:
  // KWE Status, Aggregator Ausrichtung, and Bestand Europeana/Archivportal.
  const aliases = {
    'edit-group-s': 'edit-group-status',
    'edit-group-geografische-ausrichtung': 'edit-group-ausrichtung',
    'edit-group-europenana-archivportal': 'edit-group-europeana-archivportal',
  };
  /**
   * Returns a section key that survives view/form changes and AJAX rebuilds.
   *
   * @param {HTMLDetailsElement} pane
   *   A Field Group tab pane with a generated edit-group-* ID.
   * @return {string}
   *   The normalized ID used in outgoing link fragments.
   */
  const paneKey = (pane) => {
    // Drupal appends --suffix to make repeated/AJAX-rendered HTML IDs unique.
    const id = pane.id.split('--')[0];
    return aliases[id] || id;
  };
  // Resolve the root on demand because AJAX may replace the form's DOM nodes.
  const content = () => document.querySelector('.node--view-mode-full, form.node-form');
  // Several mobile details may be open at once; remember the last opened one.
  // updateLinks() only uses this reference while it belongs to the active DOM.
  let mobilePane;

  /**
   * Adds the current section to this node's existing view/edit links.
   *
   * Covers local tasks and contextual edit links without relying on link text
   * or theme-specific markup. Navigation itself remains the browser's job.
   */
  function updateLinks() {
    const root = content();
    if (!root) {
      return;
    }

    const panes = Array.from(root.querySelectorAll(paneSelector));
    const active = panes.filter((pane) => {
      // A nested tab may still be selected inside a hidden parent section.
      if (pane.closest('.horizontal-tab-hidden')) {
        return false;
      }
      const tab = $(pane).data('horizontalTab');
      return tab ? tab.item.hasClass('selected') : pane.open;
    });
    // DOM order places a selected descendant after its selected ancestors.
    // Mobile details use the last-opened section instead of this ordering.
    const pane = active.includes(mobilePane) && !$(mobilePane).data('horizontalTab')
      ? mobilePane : active.at(-1);
    if (!pane) {
      return;
    }

    const destinations = (drupalSettings.ddbgoNodeTabs?.links || [])
      .map((link) => new URL(link, document.baseURI));
    document.querySelectorAll('a[href]').forEach((link) => {
      let url;
      try {
        url = new URL(link.href, document.baseURI);
      }
      catch {
        return;
      }
      // Match server-generated paths (including aliases/language prefixes),
      // but allow each link to carry its own query parameters. Other records
      // and external origins must not inherit this record's selected section.
      if (!destinations.some((target) =>
        target.origin === url.origin && target.pathname === url.pathname)) {
        return;
      }
      // Preserve explicit deep links; query strings such as destination stay intact.
      if (url.hash && !url.hash.startsWith(fragmentPrefix)) {
        return;
      }
      url.hash = fragmentPrefix + paneKey(pane);
      link.href = url.href;
    });
  }

  /**
   * Applies an incoming section fragment once, after initial tab setup.
   *
   * An unavailable section or a form error leaves Drupal's selection in place.
   * Match against existing panes rather than treating the fragment as a CSS
   * selector, so the URL cannot select elements outside the content's tabs.
   */
  function restoreTab() {
    const root = content();
    const hash = window.location.hash;
    if (!root || !hash.startsWith(fragmentPrefix)) {
      return;
    }
    // Drupal's validation must continue to reveal the field that needs attention.
    if (root.querySelector('.error, [aria-invalid="true"]')) {
      return;
    }

    const key = hash.slice(fragmentPrefix.length);
    const pane = Array.from(root.querySelectorAll(paneSelector))
      .find((candidate) => paneKey(candidate) === key);
    if (!pane) {
      return;
    }

    // Open outer sections first so a nested pane becomes visible as well.
    // Example: Bestand > Europeana/Archivportal > DDB-Objekte.
    const ancestors = [];
    for (let current = pane; current; current = current.parentElement.closest(paneSelector)) {
      ancestors.unshift(current);
    }
    ancestors.forEach((current) => {
      const tab = $(current).data('horizontalTab');
      if (tab) {
        // Field Group's focus() synchronizes panes, selected tab markers and
        // its hidden active-tab input; it does not focus a form input.
        tab.focus();
      }
      else {
        current.open = true;
      }
    });
    mobilePane = pane;
  }

  Drupal.behaviors.ddbgoNodeTabs = {
    attach(context) {
      // One set of document listeners per page, even when Drupal reattaches
      // behaviors to fragments returned by Paragraphs or other AJAX widgets.
      once('ddbgo-node-tabs', 'html', context).forEach(() => {
        // A microtask runs after the synchronous attachBehaviors() pass: both
        // Field Group's tab objects and validation markers are available then.
        window.queueMicrotask(() => {
          restoreTab();
          updateLinks();
        });
        // Bubbling lets Field Group's target handlers select the tab first.
        // Updating href (without preventing navigation) also supports keyboard,
        // middle-click and the browser's "open link in new tab" context menu.
        ['click', 'keydown', 'auxclick', 'contextmenu'].forEach((event) => {
          document.addEventListener(event, updateLinks);
        });
        // Native details toggle events do not bubble, so listen in capture.
        document.addEventListener('toggle', (event) => {
          if (event.target.matches(paneSelector) && event.target.open
            && !$(event.target).data('horizontalTab')) {
            mobilePane = event.target;
            updateLinks();
          }
        }, true);
      });
      // AJAX may insert fresh links. Do not restore the initial tab a second time.
      window.queueMicrotask(updateLinks);
    },
  };
})(jQuery, Drupal, once, drupalSettings);
