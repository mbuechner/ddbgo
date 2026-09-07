/**
 * @file
 * Accessible form tooltips: hover, focus, touch/click and Escape.
 *
 * Twig owns the text, IDs and aria-describedby. Tooltips stay next to their
 * buttons in the DOM. The Popover API lifts their visual presentation above
 * clipping containers; fixed positioning is the fallback. Descriptions inside
 * details temporarily move to body while open, since closed details suppress
 * even top-layer descendants. Their ID and aria-describedby remain unchanged.
 */
(function (Drupal, once) {
  let active = null;

  function position(view) {
    const { button, tooltip } = view;
    const gutter = 8;
    const width = document.documentElement.clientWidth;
    const height = window.innerHeight;
    const anchor = button.getBoundingClientRect();
    tooltip.style.setProperty('--ddbgo-tooltip-height', `${height - gutter * 2}px`);
    const rect = tooltip.getBoundingClientRect();
    const below = height - anchor.bottom - gutter;
    const above = anchor.top - gutter;
    const onTop = above >= rect.height || above > below;
    const available = Math.max(0, onTop ? above : below);
    tooltip.style.setProperty('--ddbgo-tooltip-height', `${available}px`);
    const top = onTop ? anchor.top - Math.min(rect.height, available) : anchor.bottom;
    const center = anchor.left + anchor.width / 2;
    const left = Math.max(gutter, Math.min(center - rect.width / 2, width - rect.width - gutter));
    tooltip.dataset.placement = onTop ? 'top' : 'bottom';
    tooltip.style.setProperty('--ddbgo-tooltip-arrow-left', `${Math.max(10, Math.min(center - left, rect.width - 10))}px`);
    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${Math.max(gutter, top)}px`;
  }

  function close(view) {
    window.clearTimeout(view.timer);
    if (typeof view.tooltip.hidePopover === 'function' && view.tooltip.matches(':popover-open')) {
      view.tooltip.hidePopover();
    }
    view.tooltip.hidden = true;
    if (view.placeholder) {
      view.placeholder.replaceWith(view.tooltip);
      view.placeholder = null;
    }
    view.pinned = false;
    if (active === view) {
      active = null;
    }
  }

  /** Explicit dismissal wins over focus/hover until the next interaction. */
  function dismiss(view) {
    view.dismissed = true;
    close(view);
  }

  function open(view) {
    window.clearTimeout(view.timer);
    if (view.dismissed) {
      return;
    }
    if (active && active !== view) {
      close(active);
    }
    // Keep rich descriptions outside summary in Twig. A closed details element
    // would otherwise hide its tooltip, including a native popover.
    if (!view.placeholder && view.tooltip.closest('details')) {
      view.placeholder = document.createComment('ddbgo-help-tooltip');
      view.tooltip.before(view.placeholder);
      document.body.append(view.tooltip);
    }
    view.tooltip.hidden = false;
    if (typeof view.tooltip.showPopover === 'function' && !view.tooltip.matches(':popover-open')) {
      view.tooltip.showPopover();
    }
    active = view;
    position(view);
  }

  function leave(view) {
    window.clearTimeout(view.timer);
    // Allow the pointer to cross from the icon into the tooltip itself.
    view.timer = window.setTimeout(() => {
      if (!view.overButton && !view.overTooltip && !view.focused && !view.pinned) {
        close(view);
      }
    }, 120);
  }

  Drupal.behaviors.ddbgoFormHelp = {
    attach(context) {
      once('ddbgo-form-help', '.ddbgo-help-toggle', context).forEach((button) => {
        const tooltip = document.getElementById(button.getAttribute('aria-describedby'));
        if (!tooltip) {
          return;
        }
        // Pointer, focus and click are independent reasons to keep help open.
        const view = {
          button,
          tooltip,
          focused: false,
          overButton: false,
          overTooltip: false,
          pinned: false,
          dismissed: false,
        };
        tooltip.dataset.ddbgoTooltipReady = '';
        tooltip.hidden = true;
        if (typeof tooltip.showPopover === 'function') {
          tooltip.setAttribute('popover', 'manual');
        }
        button.addEventListener('mouseenter', () => {
          view.overButton = true;
          view.dismissed = false;
          open(view);
        });
        button.addEventListener('mouseleave', () => {
          view.overButton = false;
          leave(view);
        });
        button.addEventListener('focus', () => {
          view.focused = true;
          view.dismissed = false;
          open(view);
        });
        button.addEventListener('blur', () => {
          view.focused = false;
          view.pinned = false;
          leave(view);
        });
        tooltip.addEventListener('mouseenter', () => {
          view.overTooltip = true;
          window.clearTimeout(view.timer);
        });
        tooltip.addEventListener('mouseleave', () => {
          view.overTooltip = false;
          leave(view);
        });
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          // Focus/hover may already have opened it before the first click.
          if (view.pinned) {
            dismiss(view);
          }
          else {
            view.dismissed = false;
            view.pinned = true;
            open(view);
          }
        });
      });

      once('ddbgo-form-help-dismiss', 'html').forEach(() => {
        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && active) {
            dismiss(active);
            event.preventDefault();
            event.stopPropagation();
          }
        });
        document.addEventListener('pointerdown', (event) => {
          if (active && !active.button.contains(event.target) && !active.tooltip.contains(event.target)) {
            dismiss(active);
          }
        });
        const reposition = () => {
          if (active) {
            if (active.button.isConnected) {
              position(active);
            }
            else {
              close(active);
            }
          }
        };
        window.addEventListener('resize', reposition);
        document.addEventListener('scroll', reposition, true);
      });
    },
    detach(context, settings, trigger) {
      if (trigger === 'unload' && active && context.contains(active.button)) {
        close(active);
      }
    },
  };
})(Drupal, once);
