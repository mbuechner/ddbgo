/**
 * @file
 * Accessible dropdown behavior for the DDBgo workspace navigation.
 */

(function (Drupal, once) {
  function openItem(navigation, item) {
    const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
    const dropdown = item.querySelector(':scope > .ddbgo-workspace-navigation__dropdown');
    closeAll(navigation, item);
    toggle.setAttribute('aria-expanded', 'true');
    dropdown.hidden = false;
    item.classList.add('is-open');
  }

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
      once('ddbgo-workspace-navigation', '.ddbgo-workspace-navigation', context).forEach((navigation) => {
        const supportsHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;

        navigation.querySelectorAll('.toolbar-menu__trigger').forEach((trigger) => trigger.remove());

        navigation.querySelectorAll('.ddbgo-workspace-navigation__item').forEach((item) => {
          const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
          if (!toggle) {
            return;
          }

          if (supportsHover) {
            item.addEventListener('mouseenter', () => openItem(navigation, item));
            item.addEventListener('mouseleave', () => closeItem(item));
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

        const currentPath = new URL(window.location.href).pathname.replace(/\/$/, '') || '/';
        navigation.querySelectorAll('.ddbgo-workspace-navigation__dropdown a[href]').forEach((link) => {
          // Gin prepends a hidden home link to every toolbar menu. It is only
          // structural and must never determine the active workspace tab.
          if (link.closest('.menu-item__tools')) {
            return;
          }

          const linkPath = new URL(link.href, document.baseURI).pathname.replace(/\/$/, '') || '/';
          if (linkPath === currentPath && link.getAttribute('href') !== '#') {
            link.classList.add('is-active');
            link.setAttribute('aria-current', 'page');
            link.closest('.ddbgo-workspace-navigation__item')?.classList.add('has-active-link');
          }
        });

        navigation.addEventListener('keydown', (event) => {
          if (event.key === 'Escape') {
            const openedItem = navigation.querySelector('.ddbgo-workspace-navigation__item.is-open');
            if (openedItem) {
              closeItem(openedItem, true);
            }
          }
        });

        document.addEventListener('click', (event) => {
          if (!navigation.contains(event.target)) {
            closeAll(navigation);
          }
        });
      });
    },
  };
})(Drupal, once);
