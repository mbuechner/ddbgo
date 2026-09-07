/**
 * @file
 * Accessible dropdown behavior for the DDBgo workspace navigation.
 *
 * The Twig template supplies native buttons and link lists. Buttons handle
 * Enter/Space natively; Tab follows the visible links without a focus trap.
 * Hover never opens a panel. Only one workspace panel is open at a time.
 *
 * Keep aria-expanded, hidden and .is-open synchronized through the helpers
 * below. CSS owns the responsive layout; JS only corrects floating panels
 * at viewport edges and resets state when the mobile breakpoint changes.
 */

(function (Drupal, once) {
  /**
   * Keeps an open floating panel one root-font-size away from viewport edges.
   *
   * Mobile panels use normal document flow and need no horizontal correction.
   * The CSS translation is separate from the panel's reveal animation.
   *
   * @param {HTMLElement} item
   *   Workspace list item whose dropdown is already visible for measurement.
   */
  function positionDropdown(item) {
    const dropdown = item.querySelector(':scope > .ddbgo-workspace-navigation__dropdown');
    // Always measure the original placement, including after a window resize.
    dropdown.style.removeProperty('--ddbgo-dropdown-shift');
    if (window.getComputedStyle(dropdown).position !== 'absolute') {
      return;
    }

    // Measure layout offsets so the reveal animation does not affect placement.
    const left = item.getBoundingClientRect().left + dropdown.offsetLeft;
    // Match the 1rem viewport gutter used by the dropdown's CSS max-width.
    const gutter = parseFloat(window.getComputedStyle(document.documentElement).fontSize);
    const maxLeft = document.documentElement.clientWidth - gutter - dropdown.offsetWidth;
    const visibleLeft = Math.max(gutter, Math.min(left, maxLeft));
    dropdown.style.setProperty('--ddbgo-dropdown-shift', `${visibleLeft - left}px`);
  }

  /**
   * Opens one panel, closing any other workspace panel in this navigation.
   *
   * @param {HTMLElement} navigation
   *   Workspace navigation root.
   * @param {HTMLElement} item
   *   Workspace list item to open.
   */
  function openItem(navigation, item) {
    const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
    const dropdown = item.querySelector(':scope > .ddbgo-workspace-navigation__dropdown');
    closeAll(navigation, item);
    toggle.setAttribute('aria-expanded', 'true');
    dropdown.hidden = false;
    item.classList.add('is-open');
    positionDropdown(item);
  }

  /**
   * Hides a panel and optionally returns keyboard focus to its button.
   *
   * @param {HTMLElement} item
   *   Workspace list item to close.
   * @param {boolean} restoreFocus
   *   True for Escape; false when focus is deliberately moving elsewhere.
   */
  function closeItem(item, restoreFocus = false) {
    const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
    const dropdown = item.querySelector(':scope > .ddbgo-workspace-navigation__dropdown');
    toggle.setAttribute('aria-expanded', 'false');
    dropdown.hidden = true;
    item.classList.remove('is-open');
    if (restoreFocus) {
      toggle.focus();
    }
  }

  /**
   * Closes open panels without moving focus.
   *
   * @param {HTMLElement} navigation
   *   Workspace navigation root.
   * @param {HTMLElement|null} except
   *   Optional item to keep open while switching workspaces.
   */
  function closeAll(navigation, except = null) {
    navigation
      .querySelectorAll('.ddbgo-workspace-navigation__item.is-open')
      .forEach((item) => {
        if (item !== except) {
          closeItem(item);
        }
      });
  }

  Drupal.behaviors.ddbgoWorkspaceNavigation = {
    attach(context) {
      // Drupal can attach behaviors repeatedly after AJAX updates.
      once('ddbgo-workspace-navigation', '.ddbgo-workspace-navigation', context).forEach((navigation) => {
        const menuToggle = navigation.querySelector('.ddbgo-workspace-navigation__menu-toggle');
        // Anonymous visitors only have a login link, with no dropdown controls.
        if (!menuToggle) {
          return;
        }

        // Keep this breakpoint aligned with workspace-navigation.css.
        const mobileNavigation = window.matchMedia('(max-width: 48em)');

        /**
         * Closes the mobile list and all workspace panels together.
         *
         * @param {boolean} restoreFocus
         *   Whether Escape should return focus to the mobile menu button.
         */
        const closeNavigation = (restoreFocus = false) => {
          closeAll(navigation);
          menuToggle.setAttribute('aria-expanded', 'false');
          if (restoreFocus) {
            menuToggle.focus();
          }
        };

        menuToggle.addEventListener('click', () => {
          const willOpen = menuToggle.getAttribute('aria-expanded') !== 'true';
          if (willOpen) {
            menuToggle.setAttribute('aria-expanded', 'true');
          }
          else {
            closeNavigation();
          }
        });

        // Do not carry an open mobile/desktop state across a layout change.
        mobileNavigation.addEventListener('change', () => closeNavigation());

        // Within the same layout, keep open panels aligned after resizing.
        window.addEventListener('resize', () => {
          navigation.querySelectorAll('.ddbgo-workspace-navigation__item.is-open').forEach(positionDropdown);
        });

        // Native button clicks cover mouse, touch, Enter and Space alike.
        navigation.querySelectorAll('.ddbgo-workspace-navigation__item').forEach((item) => {
          const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
          if (!toggle) {
            return;
          }

          toggle.addEventListener('click', () => {
            const willOpen = toggle.getAttribute('aria-expanded') !== 'true';
            if (willOpen) {
              openItem(navigation, item);
            }
            else {
              closeItem(item);
            }
          });
        });

        // Escape closes the inner panel first, then the mobile list if needed.
        navigation.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            const openedItem = navigation.querySelector('.ddbgo-workspace-navigation__item.is-open');
            if (openedItem) {
              closeItem(openedItem, true);
            }
            else if (menuToggle.getAttribute('aria-expanded') === 'true') {
              closeNavigation(true);
            }
          }
        });

        // Tabbing within the navigation preserves its state. Leaving it closes
        // the menus without pulling focus back from the next page element.
        navigation.addEventListener('focusout', (event) => {
          if (!navigation.contains(event.relatedTarget)) {
            closeNavigation();
          }
        });

        // Outside clicks dismiss menus without changing the click's destination.
        document.addEventListener('click', (event) => {
          if (!navigation.contains(event.target)) {
            closeNavigation();
          }
        });
      });
    },
  };
})(Drupal, once);
