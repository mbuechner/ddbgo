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
  const links = new Set();
  let destinations = [];
  let selectedKey;
  let observer;

  /**
   * Adds the current section to this node's existing view/edit links.
   *
   * Covers local tasks and contextual edit links without relying on link text
   * or theme-specific markup. Navigation itself remains the browser's job.
   */
  function currentKey() {
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
    return pane && paneKey(pane);
  }

  function destinationUrl(link) {
    let url;
    try {
      url = new URL(link.href, document.baseURI);
    }
    catch {
      return;
    }
    // Match aliases/language prefixes supplied by PHP, retaining each link's
    // query parameters. Explicit deep links and other records stay untouched.
    if (destinations.some((target) =>
      target.origin === url.origin && target.pathname === url.pathname)
      && (!url.hash || url.hash.startsWith(fragmentPrefix))) {
      return url;
    }
  }

  function updateLink(link, key, url = destinationUrl(link)) {
    if (key && url) {
      url.hash = fragmentPrefix + key;
      if (link.href !== url.href) {
        link.href = url.href;
      }
    }
  }

  function updateLinks() {
    links.forEach((link) => {
      if (!link.isConnected) {
        links.delete(link);
      }
    });
    const key = currentKey();
    if (key === selectedKey) {
      return;
    }
    selectedKey = key;
    links.forEach((link) => {
      // AJAX may remove a local task or replace the whole node form.
      const url = destinationUrl(link);
      if (url) {
        updateLink(link, key, url);
      }
      else {
        links.delete(link);
      }
    });
  }

  function collectLinks(context) {
    const collect = (link) => {
      const url = destinationUrl(link);
      if (url) {
        links.add(link);
        updateLink(link, selectedKey, url);
      }
    };
    // An AJAX context can itself be the newly inserted link.
    if (context.matches?.('a[href]')) {
      collect(context);
    }
    context.querySelectorAll('a[href]').forEach(collect);
  }

  function observeTabs() {
    const root = content();
    observer?.disconnect();
    if (root) {
      // Field Group's focus()/tabShow()/tabHide() have no change event. Their
      // selected class also covers validation and programmatic tab switches.
      observer ||= new MutationObserver((records) => {
        if (records.some((record) =>
          /(^|\s)selected(\s|$)/.test(record.oldValue || '')
            !== record.target.classList.contains('selected'))) {
          updateLinks();
        }
      });
      // Observe only tab buttons, not changing classes throughout the form.
      // Refresh the observed buttons after AJAX adds tabs or replaces a form.
      root.querySelectorAll(paneSelector).forEach((pane) => {
        const tab = $(pane).data('horizontalTab');
        if (tab) {
          observer.observe(tab.item[0], { attributes: true,
            attributeFilter: ['class'], attributeOldValue: true });
        }
      });
    }
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
      const initial = once('ddbgo-node-tabs', 'html', context).length > 0;
      if (initial) {
        // Navigation needs only its own link, including newly inserted links
        // that have not received an AJAX behavior attach yet. Read the current
        // selection synchronously before the browser opens a new tab/menu.
        ['click', 'keydown', 'auxclick', 'contextmenu'].forEach((event) => {
          document.addEventListener(event, (interaction) => {
            if (event === 'keydown' && interaction.key !== 'Enter'
              && interaction.keyCode !== 13) {
              return;
            }
            const link = interaction.target.closest?.('a[href]');
            if (link) {
              const url = destinationUrl(link);
              if (url) {
                links.add(link);
                updateLink(link, currentKey(), url);
              }
            }
          });
        });
        // Native details toggle events do not bubble, so listen in capture.
        document.addEventListener('toggle', (event) => {
          if (event.target.matches(paneSelector)
            && !$(event.target).data('horizontalTab')) {
            if (event.target.open) {
              mobilePane = event.target;
            }
            updateLinks();
          }
        }, true);
      }
      // Run after Field Group initializes (including AJAX form replacements).
      // Only the first attach inventories the document; later attaches inspect
      // their new fragment. The incoming tab is restored once per navigation.
      window.queueMicrotask(() => {
        destinations = (drupalSettings.ddbgoNodeTabs?.links || [])
          .map((link) => new URL(link, document.baseURI));
        if (initial) {
          restoreTab();
        }
        observeTabs();
        updateLinks();
        if (initial || context !== document) {
          collectLinks(context);
        }
      });
    },
  };
})(jQuery, Drupal, once, drupalSettings);
