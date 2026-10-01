/**
 * @file
 * Focus the first row weight after explicitly showing TableDrag's order fields.
 *
 * Core/Gin keep the focus on the toggle, with other row inputs before weights
 * in the Tab order. This shortens that path without changing sorting or values.
 */

(function ($, Drupal, once) {
  Drupal.behaviors.ddbgoTableDragFocus = {
    attach(context) {
      // The dependency registers before TableDrag. Wait for Core/Gin/Claro to
      // finish their synchronous behaviors, also after an AJAX replacement.
      window.queueMicrotask(() => {
        const tables = Array.from(context.querySelectorAll('table'));
        if (context.matches?.('table')) {
          tables.unshift(context);
        }

        tables.forEach((table) => {
          const instance = Drupal.tableDrag?.[table.id];
          const button = instance?.$toggleWeightButton?.[0];
          if (!button || instance.table !== table) {
            return;
          }

          // Some TableDrag columns are always visible, or manage parents/IDs
          // instead of order. Only newly revealed weight fields are relevant.
          const targets = new Set(Object.values(instance.tableSettings)
            .flatMap((group) => Object.values(group))
            .filter((setting) => setting.action === 'order' && setting.hidden && setting.target)
            .map((setting) => setting.target));
          if (!targets.size) {
            return;
          }

          // This handler follows Core's synchronous show/hide handler. Never
          // use columnschange for focus: it also fires on init, AJAX/storage
          // updates and ALL tables, not just the one whose toggle was used.
          once('ddbgo-tabledrag-focus', button).forEach(() => {
            $(button).on('click.ddbgoTableDragFocus', () => {
              const weight = Array.from(table.querySelectorAll('.tabledrag-hide select, .tabledrag-hide input'))
                .find((field) => field.closest('table') === table
                  && Array.from(field.classList).some((name) => targets.has(name))
                  && !field.matches(':disabled') && !field.readOnly
                  && $(field).is(':visible') && window.getComputedStyle(field).visibility === 'visible');

              // On hide, in empty tables or with no editable weights, return
              // to the activated toggle. Test actual visibility, not its
              // translated text or the preference shared with other tables.
              (weight || button).focus();
            });
          });
        });
      });
    },
  };
})(jQuery, Drupal, once);
