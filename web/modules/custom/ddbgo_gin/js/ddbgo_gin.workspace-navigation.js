/**
 * @file
 * Accessible dropdown behavior for the DDBgo workspace navigation.
 */

(function (Drupal, once) {
  function setActiveWorkspaceItem(navigation, bundleName) {
    navigation.querySelectorAll('.ddbgo-workspace-navigation__item').forEach((item) => {
      item.classList.remove('has-active-link');
      const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
      if (toggle) {
        toggle.removeAttribute('aria-current');
      }
    });

    const item = navigation.querySelector(`.ddbgo-workspace-navigation__item--${bundleName}`);
    if (!item) {
      return;
    }

    item.classList.add('has-active-link');
    const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
    if (toggle) {
      toggle.setAttribute('aria-current', 'page');
    }
  }

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
        const menuToggle = navigation.querySelector('.ddbgo-workspace-navigation__menu-toggle');
        if (!menuToggle) {
          return;
        }

        const mobileNavigation = window.matchMedia('(max-width: 48em)');
        const supportsHover = window.matchMedia('(hover: hover) and (pointer: fine)');

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

        mobileNavigation.addEventListener('change', () => closeNavigation());

        navigation.querySelectorAll('.toolbar-menu__trigger').forEach((trigger) => trigger.remove());

        navigation.querySelectorAll('.ddbgo-workspace-navigation__item').forEach((item) => {
          const toggle = item.querySelector(':scope > .ddbgo-workspace-navigation__toggle');
          if (!toggle) {
            return;
          }

          if (supportsHover.matches) {
            item.addEventListener('mouseenter', () => {
              if (!mobileNavigation.matches) {
                openItem(navigation, item);
              }
            });
            item.addEventListener('mouseleave', () => {
              if (!mobileNavigation.matches) {
                closeItem(item);
              }
            });
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

        const activeBundle = document.documentElement.dataset.ddbgoNodeBundle
          || document.documentElement.getAttribute('data-ddbgo-node-bundle');
        if (activeBundle) {
          setActiveWorkspaceItem(navigation, activeBundle);
        }

        const currentPath = new URL(window.location.href).pathname.replace(/\/$/, '') || '/';
        navigation.querySelectorAll('.ddbgo-workspace-navigation__dropdown a[href]').forEach((link) => {
          // Gin prepends a hidden home link to every toolbar menu. It is only
          // structural and must never determine the active workspace tab.
          if (link.closest('.menu-item__tools')) {
            return;
          }

          const linkPath = new URL(link.href, document.baseURI).pathname.replace(/\/$/, '') || '/';
          if (!activeBundle && linkPath === currentPath && link.getAttribute('href') !== '#') {
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
            else if (menuToggle.getAttribute('aria-expanded') === 'true') {
              closeNavigation(true);
            }
          }
        });

        document.addEventListener('click', (event) => {
          if (!navigation.contains(event.target)) {
            closeNavigation();
          }
        });
      });
    },
  };
})(Drupal, once);
