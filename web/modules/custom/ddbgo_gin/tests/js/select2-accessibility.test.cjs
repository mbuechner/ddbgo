/**
 * Build a local regression fixture with the installed Select2/Drupal code.
 * Run with Node, then open the printed HTML path. No requests or submissions.
 * Attribute checks do not replace a browser accessibility-tree/screenreader test.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const script = file => `<script src="${url(file)}"></script>`;
const output = process.argv[2] || path.join(os.tmpdir(), 'ddbgo-select2-accessibility-test.html');

fs.writeFileSync(output, `<!doctype html><html lang="de"><meta charset="utf-8">
<title>Select2: Feldnamen und Hilfetexte</title>
<link rel="stylesheet" href="${url('libraries/select2/dist/css/select2.css')}">
<style>body{font:16px/1.5 sans-serif;max-width:800px;margin:2rem auto;padding:1rem}.field{margin:1rem 0}label{display:block}#result{white-space:pre-wrap}</style>
<h1>Select2: Feldnamen und Hilfetexte</h1>
<p>Lokaler Test mit echten Bibliotheken. Anschließend mit Tab und Screenreader prüfen:
Feldname und Wert müssen getrennt verfügbar sein, der Hilfetext als Beschreibung.
Es werden keine Formulardaten gesendet.</p>
<pre id="result">pending</pre><form id="fixtures"></form>
<p id="extra-help" hidden>Zusätzliche Beschreibung.</p>
<script>window.drupalSettings={};</script>
${script('core/assets/vendor/jquery/jquery.min.js')}
${script('core/misc/drupal.js')}
${script('core/assets/vendor/once/once.min.js')}
${script('core/assets/vendor/sortable/Sortable.min.js')}
${script('libraries/select2/dist/js/select2.js')}
${script('libraries/select2/dist/js/i18n/de.js')}
<!-- Production dependency order: fix registers before the contrib behavior. -->
${script('modules/custom/ddbgo_gin/js/ddbgo_gin.select2-accessibility.js')}
${script('modules/contrib/select2/js/select2.js')}
<script>
(async () => {
  const result = document.getElementById('result');
  let checks = 0;
  const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
  const refs = (element, attribute) => (element.getAttribute(attribute) || '').trim().split(/\\s+/).filter(Boolean);
  const name = element => element.getAttribute('aria-labelledby')
    ? refs(element, 'aria-labelledby').map(id => document.getElementById(id)?.textContent.trim()).join(' ')
    : element.getAttribute('aria-label');
  const instance = select => jQuery(select).data('select2');
  const selection = select => instance(select).$selection[0];
  const form = document.getElementById('fixtures');
  let submissions = 0;
  form.addEventListener('submit', event => { event.preventDefault(); submissions++; });
  function field(id, title, multiple = false, extra = '') {
    const wrapper = document.createElement('div');
    wrapper.className = 'field';
    wrapper.innerHTML = '<label for="'+id+'">'+title+'</label>'
      + '<select class="select2-widget" id="'+id+'" '+(multiple ? 'multiple' : '')+' '
      + 'aria-describedby="'+id+'-help" '+extra+'><option></option>'
      + '<option value="dr">Doktor</option><option value="prof">Professor</option></select>'
      + '<div role="tooltip" id="'+id+'-help" hidden>Hilfetext für '+title+'.</div>';
    form.append(wrapper);
    const select = wrapper.querySelector('select');
    jQuery(select).data('select2-config', {width:'100%', multiple, language:'de', placeholder:'Nicht ausgewählt'});
    return select;
  }
  async function attach(context) {
    Drupal.attachBehaviors(context, drupalSettings);
    await new Promise(resolve => queueMicrotask(resolve));
  }
  function labelled(select, expected) {
    const widget = selection(select);
    check(name(widget) === expected, select.id+': replacement has original field name');
    check(refs(widget, 'aria-describedby').includes(select.id+'-help'), select.id+': hidden help linked');
    check(select.getAttribute('aria-hidden') === 'true', select.id+': native select stays hidden');
    check(widget.getAttribute('role') === 'combobox', select.id+': role unchanged');
    return widget;
  }
  try {
    const title = field('title', 'Titel');
    const people = field('people', 'Personen', true);
    people.querySelector('[value="dr"]').selected = true;
    const labelledby = field('explicit', 'Sichtbares Label', false, 'aria-labelledby="explicit-name"');
    const explicitLabel = document.createElement('span');
    explicitLabel.id = 'explicit-name'; explicitLabel.textContent = 'Expliziter Feldname';
    labelledby.parentElement.append(explicitLabel);
    const ariaLabel = field('aria-label', 'Sichtbares Label', false, 'aria-label="Alternativer Feldname"');
    const noHelp = field('no-help', 'Ohne Hilfetext');
    noHelp.removeAttribute('aria-describedby');
    const unnamed = field('unnamed', 'Entferntes Label');
    unnamed.labels[0].remove();
    const native = document.createElement('select');
    native.id = 'native'; form.append(native);

    await attach(document);
    let widget = labelled(title, 'Titel');
    const labelId = title.labels[0].id;
    check(Boolean(labelId), 'Missing native label ID assigned');
    check(name(instance(title).dropdown.$search[0]) === 'Titel', 'Detached popup search named before focus');
    check(refs(instance(title).dropdown.$search[0], 'aria-describedby').includes('title-help'), 'Detached popup search has help');
    labelled(labelledby, 'Expliziter Feldname');
    check(!labelledby.labels[0].id, 'Explicit ARIA name does not need new visible-label ID');
    labelled(ariaLabel, 'Alternativer Feldname');
    check(!selection(ariaLabel).hasAttribute('aria-labelledby'), 'Explicit aria-label is not overridden by value reference');
    check(!selection(noHelp).hasAttribute('aria-describedby'), 'No description is invented');
    check(selection(unnamed).getAttribute('aria-labelledby').endsWith('-container'), 'Missing original name retains Select2 fallback');
    check(!instance(native), 'Native selects are not enhanced');

    const multi = labelled(people, 'Personen');
    const inline = instance(people).selection.$search[0];
    check(name(inline) === 'Personen', 'Multi inline focus target has field name');
    check(refs(inline, 'aria-describedby').includes(multi.querySelector('.select2-selection__rendered').id), 'Selected-item description retained');
    check(refs(inline, 'aria-describedby').includes('people-help'), 'Multi inline help appended');
    check(!inline.hasAttribute('aria-label'), 'Generic search label removed when field name is available');

    const before = widget.outerHTML;
    await attach(document); await attach(title.parentElement);
    check(title.labels[0].id === labelId && widget.outerHTML === before, 'Repeated attach is idempotent');
    jQuery(title).val('dr').trigger('change');
    check(name(widget) === 'Titel', 'Field name stays stable after selection');
    check(widget.querySelector('.select2-selection__rendered').textContent === 'Doktor', 'Selected value remains available');
    let nameAtFocus;
    const search = instance(title).dropdown.$search[0];
    const firstFocus = new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Popup search did not receive focus')), 1000);
      search.addEventListener('focus', () => {
        clearTimeout(timeout);
        nameAtFocus = name(search);
        resolve();
      }, {once:true});
    });
    jQuery(title).select2('open');
    // Select2's first focus attempt precedes attaching the dropdown to body;
    // its setTimeout retry delivers the first actual browser focus event.
    await firstFocus;
    check(nameAtFocus === 'Titel', 'Popup search has context on its first focus');
    search.value = 'Professor'; jQuery(search).trigger('input');
    check(document.querySelector('.select2-results').textContent.includes('Professor'), 'Search still finds an option');
    jQuery(search).trigger(jQuery.Event('keydown', {which:13, keyCode:13}));
    check(title.value === 'prof', 'Enter still selects the matching option');
    jQuery(title).select2('open');
    jQuery(search).trigger(jQuery.Event('keydown', {which:27, keyCode:27}));
    check(widget.getAttribute('aria-expanded') === 'false', 'Escape still closes the popup');

    const oldWidget = widget;
    jQuery(title).select2('destroy');
    jQuery(title).select2(jQuery(title).data('select2-config'));
    // Preserve descriptions supplied by another adapter/integration as well.
    selection(title).setAttribute('aria-describedby', 'extra-help title-help');
    // Existing instance, with the select itself as the AJAX attachment context.
    await attach(title);
    widget = labelled(title, 'Titel');
    check(widget !== oldWidget, 'Reinitialized instance receives the fix');
    check(refs(widget, 'aria-describedby').join(' ') === 'extra-help title-help', 'Existing description references merged without duplicates');
    check(title.labels[0].id === labelId, 'Label ID retained after reinitialization');
    jQuery(title).val('dr').trigger('change');

    const late = field('ajax-person', 'Nachgeladene Person');
    late.labels[0].id = 'server-label-id';
    await attach(late.parentElement);
    labelled(late, 'Nachgeladene Person');
    check(late.labels[0].id === 'server-label-id', 'Existing server-generated label ID retained');
    jQuery(late).select2('destroy');
    late.parentElement.remove();
    const replacement = field('ajax-person', 'Nachgeladene Person');
    await attach(replacement.parentElement);
    labelled(replacement, 'Nachgeladene Person');
    const labelIds = Array.from(form.querySelectorAll('label[id]'), label => label.id);
    check(new Set(labelIds).size === labelIds.length, 'AJAX label IDs remain unique');
    check(submissions === 0, 'No initialization or correction submits the form');

    result.textContent = 'PASS: '+checks+' checks. Manuelle Screenreader-Probe bereit.';
    result.dataset.complete = 'true';
  } catch (error) {
    result.textContent = 'FAIL: '+error.stack;
    result.dataset.complete = 'true';
  }
})().catch(error => { document.getElementById('result').textContent = 'FAIL: '+error.stack; });
</script></html>`);
console.log(output);
