/**
 * @file
 * Restores navigation for Gin administration menu parent links.
 */

(function (Drupal, once) {
  /**
   * Navigates for an unmodified primary-button click.
   *
   * @param {MouseEvent} event
   *   The click event.
   * @param {string} url
   *   The destination URL.
   */
  function navigate(event, url) {
    if (
      event.button !== 0 ||
      event.altKey ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey
    ) {
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    window.location.assign(url);
  }

  Drupal.behaviors.ddbgoGinToolbarNavigation = {
    attach(context) {
      once('ddbgo-gin-toolbar-navigation', 'body', context).forEach(() => {
        document.addEventListener(
          'click',
          (event) => {
            const target =
              event.target instanceof Element
                ? event.target
                : event.target.parentElement;

            // Legacy/classic Gin toolbar: always follow the actual link.
            const adminLink = target.closest(
              '#toolbar-item-administration-tray a[href]',
            );
            if (
              adminLink &&
              adminLink.getAttribute('href') !== '#' &&
              adminLink.href
            ) {
              navigate(event, adminLink.href);
              return;
            }

            // New Gin navigation: the label navigates, the icon still expands.
            const navigationLabel = target.closest(
              '#gin-toolbar-bar .toolbar-block__content--admin > ' +
                '.toolbar-menu__item--has-dropdown[data-url] > ' +
                'button.toolbar-link .toolbar-link__label',
            );
            if (navigationLabel) {
              const item = navigationLabel.closest(
                '.toolbar-menu__item[data-url]',
              );
              navigate(event, new URL(item.dataset.url, document.baseURI).href);
            }
          },
          true,
        );
      });
    },
  };
})(Drupal, once);
