/**
 * Generate a local browser fixture with the installed TableDrag implementation.
 * Run with Node [gin|core], then open the printed path (also with ?saved=1).
 * No requests/submissions. Native Enter/Space activation is checked separately
 * with real browser keys; programmatic clicks exercise the shared click path.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const mode = process.argv[2] || 'gin';
if (!['gin', 'core'].includes(mode)) throw new Error('Use gin or core');
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const script = file => `<script src="${url(file)}"></script>`;
const output = path.join(os.tmpdir(), `ddbgo-tabledrag-focus-${mode}-test.html`);

fs.writeFileSync(output, `<!doctype html><html lang="de"><meta charset="utf-8">
<title>TableDrag (${mode}): Fokus auf Zeilenreihenfolge</title>
<link rel="stylesheet" href="${url('core/themes/claro/css/components/tabledrag.css')}">
<style>body{font:16px/1.5 sans-serif;max-width:950px;margin:2rem auto;padding:1rem}table{border-collapse:collapse;margin:.5rem 0 1.5rem;width:100%}td,th{padding:.5rem;border:1px solid #aaa}label{display:block}.visually-hidden{position:absolute;clip-path:inset(50%);width:1px;height:1px;overflow:hidden}.tabledrag-handle{display:inline-block;min-width:24px;min-height:24px}#result{white-space:pre-wrap}</style>
<h1>TableDrag (${mode}): Fokus auf Zeilenreihenfolge</h1>
<p>Nach den Tests: Umschaltknopf mit Tab erreichen und per Enter/Leertaste aktivieren.
Beim Anzeigen muss das erste bedienbare Reihenfolgefeld seiner Tabelle fokussiert
sein, beim Ausblenden der Knopf. Kein Speichern, keine Serveranfragen.</p>
<pre id="result">pending</pre><form id="fixtures"><label>Vorher<input id="before" name="before" value="unverändert"></label></form>
<script>
window.drupalSettings={tableDrag:{}};
if (new URLSearchParams(location.search).has('saved')) localStorage.setItem('Drupal.tableDrag.showWeight','true');
else localStorage.removeItem('Drupal.tableDrag.showWeight');
</script>
${script('core/assets/vendor/jquery/jquery.min.js')}
${script('core/misc/drupal.js')}
${script('core/assets/vendor/once/once.min.js')}
<!-- Production dependency order: fix registers before TableDrag. -->
${script('modules/custom/ddbgo_gin/js/ddbgo_gin.tabledrag-focus.js')}
${script(mode === 'gin' ? 'themes/contrib/gin/js/overrides/tabledrag.js' : 'core/misc/tabledrag.js')}
${script('core/themes/claro/js/tabledrag.js')}
<script>
(async () => {
  const result = document.getElementById('result');
  const form = document.getElementById('fixtures');
  const before = document.getElementById('before');
  let checks = 0, submissions = 0;
  const check = (ok, message) => { if (!ok) throw new Error(message); checks++; };
  form.addEventListener('submit', event => { event.preventDefault(); submissions++; });
  const instance = table => Drupal.tableDrag[table.id];
  const toggle = table => instance(table).$toggleWeightButton[0];
  const weights = table => Array.from(table.querySelectorAll('select, input[type="number"]'))
    .filter(field => field.closest('table') === table && field.classList.contains(table.id+'-weight'));
  const values = () => JSON.stringify(Array.from(new FormData(form)));
  async function attach(context) {
    Drupal.attachBehaviors(context, drupalSettings);
    await new Promise(resolve => queueMicrotask(resolve));
  }
  function table(id, types = ['select', 'select'], hidden = true, wrapper = form) {
    const element = document.createElement('table');
    element.id = id;
    element.innerHTML = '<thead><tr><th>Griff</th><th>Eintrag</th><th>Reihenfolge</th></tr></thead><tbody>'
      + types.map((type, row) => '<tr id="'+id+'-row-'+row+'" class="draggable"><td></td><td>'
        + '<label>Eintrag '+(row+1)+'<input name="'+id+'-text-'+row+'" value="Eintrag '+(row+1)+'"></label></td>'
        + '<td><label class="visually-hidden" for="'+id+'-weight-'+row+'">Reihenfolge für Zeile '+(row+1)+'</label>'
        + (type === 'select' ? '<select id="'+id+'-weight-'+row+'" name="'+id+'-weight-'+row+'" class="'+id+'-weight">'
          + [-2,-1,0,1,2,3,4,5].map(value => '<option '+(value===row?'selected':'')+'>'+value+'</option>').join('')+'</select>'
          : '<input type="number" id="'+id+'-weight-'+row+'" name="'+id+'-weight-'+row+'" class="'+id+'-weight" value="'+row+'">')
        + '</td></tr>').join('')+'</tbody>';
    wrapper.append(element);
    drupalSettings.tableDrag[id] = {[id+'-weight']:{0:{action:'order', relationship:'sibling', group:id+'-weight', target:id+'-weight', hidden, limit:0}}};
    return element;
  }
  function preference(show) {
    const focused = document.activeElement;
    if (show) localStorage.setItem('Drupal.tableDrag.showWeight','true');
    else localStorage.removeItem('Drupal.tableDrag.showWeight');
    // Simulate another browser tab changing the preference, through the real
    // TableDrag storage handler; no replacement/mock sorting implementation.
    window.dispatchEvent(new StorageEvent('storage', {key:'Drupal.tableDrag.showWeight', newValue:show?'true':null}));
    check(document.activeElement === focused, 'Preference/storage update does not steal focus');
  }
  function show(table, expected) {
    preference(false);
    before.focus();
    const snapshot = values();
    toggle(table).click();
    check(document.activeElement === expected, table.id+': show focuses first own editable weight');
    check(values() === snapshot, table.id+': focusing does not change field values');
  }
  function hide(table) {
    toggle(table).click();
    check(document.activeElement === toggle(table), table.id+': hide focuses the activated toggle');
    check(weights(table).every(field => !jQuery(field).is(':visible')), table.id+': weights hidden');
  }
  try {
    const scroll = document.createElement('div'); scroll.className = 'gin-table-scroll-wrapper'; form.append(scroll);
    const email = table('email', undefined, true, scroll);
    const people = table('people', ['number', 'select', 'number']);
    const contact = table('contact');
    const nested = table('nested', ['number'], true, people.tBodies[0].rows[0].cells[1]);
    const unavailable = table('unavailable', ['number', 'select']);
    weights(unavailable)[0].readOnly = true;
    weights(unavailable)[1].disabled = true;
    const empty = table('empty', []);
    const permanent = table('permanent', ['number'], false);
    before.focus();
    await attach(document);
    check(document.activeElement === before, 'Initial attachment does not move focus');
    check(jQuery(weights(email)[0]).is(':visible') === new URLSearchParams(location.search).has('saved'), 'Saved initial preference respected without focus change');

    show(email, weights(email)[0]); hide(email);
    show(contact, weights(contact)[0]); hide(contact);
    // Disabled fieldsets and invisible/read-only inputs must be skipped.
    const disabled = document.createElement('fieldset'); disabled.disabled = true;
    weights(people)[0].parentElement.append(disabled); disabled.append(weights(people)[0]);
    weights(people)[1].style.visibility = 'hidden';
    // Deliberately overlap a target class after Core's column initialization:
    // own-table restriction must still skip the earlier nested weight.
    weights(nested)[0].classList.add('people-weight');
    show(people, weights(people)[2]); hide(people);
    show(nested, weights(nested)[0]); hide(nested);
    show(unavailable, toggle(unavailable)); hide(unavailable);
    show(empty, toggle(empty)); hide(empty);
    preference(false); toggle(permanent).focus(); toggle(permanent).click();
    check(document.activeElement === toggle(permanent), 'Always-visible order field is not a reveal focus target');
    check(jQuery(weights(permanent)[0]).is(':visible'), 'Always-visible order field stays visible');

    preference(false); before.focus();
    await attach(document); await attach(email);
    const first = weights(email)[0];
    let focusCalls = 0;
    const originalFocus = first.focus.bind(first);
    first.focus = (...args) => { focusCalls++; return originalFocus(...args); };
    toggle(email).click();
    check(focusCalls === 1, 'Repeated attachment does not duplicate focus handlers');
    hide(email);
    first.focus = originalFocus;

    preference(true); before.focus();
    const ajaxWrapper = document.createElement('div'); form.append(ajaxWrapper);
    const ajax = table('ajax', ['number'], true, ajaxWrapper);
    await attach(ajaxWrapper);
    check(document.activeElement === before && jQuery(weights(ajax)[0]).is(':visible'), 'AJAX initialization restores visible weights without stealing focus');
    show(ajax, weights(ajax)[0]); hide(ajax);
    const oldButton = toggle(ajax);
    // Simulate replacing a table and its toggle wrapper under the same ID.
    instance(ajax).$toggleWeightButton.closest('.tabledrag-toggle-weight-wrapper').remove();
    ajax.remove();
    const replacement = table('ajax', ['select'], true, ajaxWrapper);
    before.focus(); await attach(ajaxWrapper);
    check(document.activeElement === before && toggle(replacement) !== oldButton, 'Replacement creates a new handler without initial focus change');
    show(replacement, weights(replacement)[0]); hide(replacement);
    check(submissions === 0, 'Toggle/focus never submits the form');

    // Leave a straightforward table available for native Enter/Space and Tab
    // tests, plus checks of the focused element in the accessibility tree.
    preference(false); before.focus();
    window.ddbgoTableDragFocusFixture = {email, toggle:toggle(email), weight:weights(email)[0]};
    result.textContent = 'PASS: '+checks+' checks. Manuelle Tastatur-Probe bereit.';
    result.dataset.complete = 'true';
  } catch (error) {
    result.textContent = 'FAIL: '+error.stack;
    result.dataset.complete = 'true';
  }
})().catch(error => {document.getElementById('result').textContent = 'FAIL: '+error.stack;});
</script></html>`);
console.log(output);
