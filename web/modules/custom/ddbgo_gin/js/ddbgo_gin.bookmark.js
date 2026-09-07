/**
 * @file
 * Places the full-node bookmark action beside the primary local tasks.
 */

(function (Drupal, once) {
  Drupal.behaviors.ddbgoBookmarkAction = {
    attach(context) {
      once(
        'ddbgo-bookmark-action',
        '.node--view-mode-full .flag-bookmark',
        context,
      ).forEach((bookmark) => {
        const pageHeader = document.querySelector('.gin-frontend__page-header');
        const localTasks = pageHeader?.querySelector('.block-local-tasks-block');
        if (!pageHeader || !localTasks) {
          return;
        }

        const actionRow = document.createElement('div');
        actionRow.className = 'ddbgo-page-actions';
        localTasks.before(actionRow);
        actionRow.append(localTasks, bookmark);
      });
    },
  };
})(Drupal, once);
