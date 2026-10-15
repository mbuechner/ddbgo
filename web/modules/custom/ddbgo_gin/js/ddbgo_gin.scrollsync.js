/**
 * @file
 * Synchronize Gin table/header scrolling through valid data attributes.
 *
 * Retain syncscroll's proportional movement and reset API. Rebuild groups on
 * AJAX attach/detach so replaced elements cannot retain scroll listeners.
 */

(function (window, document, Drupal) {
  let subscriptions = [];

  function reset(excludedContext) {
    subscriptions.forEach(({ element, listener }) => {
      element.removeEventListener('scroll', listener);
    });
    subscriptions = [];

    const groups = new Map();
    Array.from(document.getElementsByClassName('syncscroll')).forEach((element) => {
      if (excludedContext?.contains(element)) {
        return;
      }
      // Other Gin templates may still use the original library's group marker.
      const name = element.getAttribute('data-syncscroll') || element.getAttribute('name');
      if (!name) {
        return;
      }
      const scroller = element.scroller || element;
      if (!groups.has(name)) {
        groups.set(name, new Set());
      }
      groups.get(name).add(scroller);
    });

    groups.forEach((elements) => {
      const positions = new Map(Array.from(elements, (element) => [element, {
        x: element.scrollLeft,
        y: element.scrollTop,
      }]));

      elements.forEach((element) => {
        const listener = () => {
          const position = positions.get(element);
          const x = element.scrollLeft;
          const y = element.scrollTop;
          const changedX = x !== position.x;
          const changedY = y !== position.y;
          position.x = x;
          position.y = y;

          const width = element.scrollWidth - element.clientWidth;
          const height = element.scrollHeight - element.clientHeight;
          elements.forEach((other) => {
            if (other === element) {
              return;
            }
            const otherPosition = positions.get(other);
            if (changedX && width > 0) {
              const nextX = Math.round(x / width * Math.max(0, other.scrollWidth - other.clientWidth));
              // Record the destination before writing to avoid feedback loops.
              otherPosition.x = nextX;
              if (other.scrollLeft !== nextX) {
                other.scrollLeft = nextX;
              }
            }
            if (changedY && height > 0) {
              const nextY = Math.round(y / height * Math.max(0, other.scrollHeight - other.clientHeight));
              otherPosition.y = nextY;
              if (other.scrollTop !== nextY) {
                other.scrollTop = nextY;
              }
            }
          });
        };
        element.addEventListener('scroll', listener);
        subscriptions.push({ element, listener });
      });
    });
  }

  window.syncscroll = { reset };
  Drupal.behaviors.ddbgoScrollSync = {
    attach() {
      reset();
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload') {
        reset(context);
      }
    },
  };

  if (document.readyState === 'complete') {
    reset();
  }
  else {
    window.addEventListener('load', () => reset(), { once: true });
  }
})(window, document, Drupal);
