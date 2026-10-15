/**
 * @file
 * Open native FieldGroup details when a contained control is invalid.
 *
 * FieldGroup's tab validator reads aria-expanded on summary. Native summary
 * exposes that state without an attribute, so use details.open instead. Keep
 * its behavior, once token, event namespace and enhanced-tab exclusions.
 */

(function ($, Drupal, once) {
  const onTabInvalid = function (event) {
    $(event.target)
      .parents('details:not([open])')
      .not('.horizontal-tabs-pane, .vertical-tabs__pane')
      .each(function () {
        this.open = true;
      });
  };

  Drupal.behaviors.fieldGroupTabValidation = {
    attach(context) {
      $(once('field-group-tab-validation', $('.field-group-tab :input', context)))
        .on('invalid.field_group', onTabInvalid);
    },
  };
})(jQuery, Drupal, once);
