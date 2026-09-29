/**
 * Generate a local browser fixture with the installed Tagify/Drupal integration.
 * Run with Node and open the printed HTML path. No server requests are made.
 * Synthetic keys test selection side effects, NOT native Tab focus movement.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const script = file => `<script src="${url(file)}"></script>`;
const output = process.argv[2] || path.join(os.tmpdir(), 'ddbgo-tagify-keyboard-test.html');

fs.writeFileSync(output, `<!doctype html><html lang="de"><meta charset="utf-8">
<title>Tagify: Tab und Shift+Tab</title>
${['libraries/tagify/dist/tagify.css', 'modules/contrib/tagify/css/tagify.css', 'modules/custom/ddbgo_gin/css/ddbgo_gin.form-controls.css'].map(file => `<link rel="stylesheet" href="${url(file)}">`).join('\n')}
<style>body{font:16px/1.5 sans-serif;max-width:850px;margin:2rem auto;padding:1rem}label{display:block;margin-top:1rem}#result{white-space:pre-wrap}.tagify{margin:.5rem 0}</style>
<h1>Tagify-Tastaturprüfung</h1>
<p>Synthetische Tasten prüfen Auswahl und Übermittlung, keinen nativen Fokuswechsel.
Nach den Tests bleibt unten eine manuelle Probe: Tab/Shift+Tab müssen zum nächsten/vorigen Feld führen,
ohne Tags auszuwählen. Auch mit Suchtext und offenem Dropdown prüfen. Es werden keine Daten gesendet.</p>
<pre id="result">pending</pre><div id="fixtures"></div>
<script>window.drupalSettings={tagify_select:{information_message:{limit_tag:'Limit',no_matching_suggestions:'Keine Treffer'}}};</script>
${script('core/misc/drupal.js')}
${script('core/assets/vendor/once/once.min.js')}
${script('core/assets/vendor/sortable/Sortable.min.js')}
${script('libraries/tagify/dist/tagify.js')}
${script('modules/contrib/tagify/js/tagify.helpers.js')}
<!-- Production dependency order: the fix registers before the contrib behavior. -->
${script('modules/custom/ddbgo_gin/js/ddbgo_gin.tagify-keyboard.js')}
${script('modules/contrib/tagify/js/tagify.js')}
${script('modules/custom/ddbgo_gin/js/ddbgo_gin.exposed-filters.js')}
<script>
(async () => {
  const results = [];
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const check = (ok, message) => { if (!ok) throw new Error(message); };
  let active;
  let serial = 0;
  const values = select => Array.from(select.selectedOptions, option => option.value).sort();
  const snapshot = ({form, select, tagify}) => JSON.stringify({
    tags: tagify.value.map(item => item.value).sort(), select: values(select), data: Array.from(new FormData(form)),
  });
  const key = (widget, name, shiftKey = false) => {
    const event = new KeyboardEvent('keydown', {key:name, code:name, shiftKey, bubbles:true, cancelable:true});
    widget.tagify.DOM.input.dispatchEvent(event);
    return event;
  };
  async function fixture(selected = []) {
    if (active) active.tagify.destroy();
    const form = document.createElement('form');
    const id = 'keyboard-tags-' + (++serial);
    form.innerHTML = '<label>Vorher <input name="before" value="unverändert"></label>'
      + '<label for="'+id+'">Tags</label><select id="'+id+'" multiple name="tags[]" '
      + 'class="tagify-select-widget form-element form-element--type-select-multiple" '
      + 'data-cardinality="-1" data-identifier="'+id+'" data-match-limit="0" data-match-operator="0" data-ddbgo-tag-auto-submit>'
      + [['1','Archiv'],['2','Bibliothek'],['3','Museum']].map(([value,text]) => '<option value="'+value+'" '+(selected.includes(value)?'selected':'')+'>'+text+'</option>').join('')
      + '</select><label>Nachher <input name="after" value="unverändert"></label>'
      + '<button type="submit" data-ddbgo-tag-auto-submit-click>Anwenden (nur lokal)</button>';
    document.getElementById('fixtures').replaceChildren(form);
    const select = form.querySelector('select');
    const submissions = [];
    form.addEventListener('submit', event => { event.preventDefault(); submissions.push(values(select)); });
    Drupal.attachBehaviors(form, drupalSettings);
    const tagify = form.querySelector('input.tagify-select-widget').__tagify;
    check(tagify, 'Drupal wrapper did not initialize Tagify');
    // Repeated attachment must not register multiple capturing Tab handlers.
    Drupal.attachBehaviors(form, drupalSettings);
    Drupal.attachBehaviors(form, drupalSettings);
    await delay(180);
    check(submissions.length === 0, 'Initialization submitted the form');
    return active = {form, select, tagify, submissions};
  }
  async function prepare(widget, query, open) {
    const input = widget.tagify.DOM.input;
    input.focus();
    input.textContent = query;
    input.dispatchEvent(new InputEvent('input', {bubbles:true, inputType:'insertText', data:query}));
    await delay(100);
    if (open) widget.tagify.dropdown.show(query);
    else widget.tagify.dropdown.hide();
    await delay(100);
    check(Boolean(widget.tagify.state.dropdown.visible) === open, 'Dropdown precondition failed');
  }
  async function test(name, callback) {
    try { await callback(); results.push('PASS: ' + name); }
    catch (error) { results.push('FAIL: ' + name + ' — ' + error.message); }
    document.getElementById('result').textContent = results.join('\\n');
  }
  for (const shift of [false, true]) for (const selected of [[], ['3']]) {
    for (const query of ['', 'Arc', 'Archiv']) for (const open of [false, true]) {
      await test((shift?'Shift+Tab':'Tab') + ', tags=' + selected.length + ', query=' + JSON.stringify(query) + ', open=' + open, async () => {
        const widget = await fixture(selected);
        await prepare(widget, query, open);
        const before = snapshot(widget);
        const count = widget.submissions.length;
        check(count === 0, 'Typing already changed/submitted the selection');
        let hides = 0;
        const hide = widget.tagify.dropdown.hide;
        widget.tagify.dropdown.hide = (...args) => { hides++; return hide.apply(widget.tagify, args); };
        const event = key(widget, 'Tab', shift);
        check(!event.defaultPrevented, 'Tab default was prevented');
        check(hides === 1, 'Repeated attach installed duplicate/missing Tab handlers: ' + hides);
        // Simulate the following blur only; this is not a native Tab-focus test.
        widget.tagify.DOM.input.blur();
        await delay(250);
        check(snapshot(widget) === before, 'Tab/blur changed tags or submitted form values');
        check(widget.submissions.length === count, 'Tab/blur triggered an automatic submit');
        check(!widget.tagify.state.dropdown.visible, 'Tab left the dropdown open');
      });
    }
  }
  await test('Arrow navigation and Enter still select exactly one suggestion', async () => {
    const widget = await fixture();
    await prepare(widget, '', true);
    const first = widget.tagify.state.ddItemData?.value;
    key(widget, 'ArrowDown');
    await delay(80);
    const target = widget.tagify.state.ddItemData?.value;
    check(target && target !== first, 'ArrowDown did not change the highlighted suggestion');
    key(widget, 'Enter');
    await delay(250);
    check(values(widget.select).join() === String(target), 'Enter did not select the highlighted suggestion');
    check(widget.submissions.length === 1 && widget.submissions[0].join() === String(target), 'Explicit selection did not submit exactly its current value');
  });
  await test('Remove button keeps focus and Enter still removes its tag', async () => {
    const widget = await fixture(['1', '3']);
    const remove = widget.tagify.DOM.scope.querySelector('.tagify__tag__removeBtn');
    remove.focus();
    await delay(80);
    // A targeted focus-handler check, not a simulation of native Shift+Tab.
    check(document.activeElement === remove, 'Tagify redirected remove-button focus to its input');
    remove.dispatchEvent(new KeyboardEvent('keydown', {key:'Enter', code:'Enter', bubbles:true, cancelable:true}));
    await delay((Number(widget.tagify.CSSVars.tagHideTransition) || 300) + 200);
    check(values(widget.select).join() === '3', 'Enter on remove button did not remove only its tag');
    check(widget.submissions.length === 1 && widget.submissions[0].join() === '3', 'Removal did not submit the remaining selection exactly once');
  });
  await test('New widget after AJAX-style replacement receives the fix', async () => {
    const previous = active.tagify;
    const widget = await fixture(['3']);
    check(widget.tagify !== previous, 'Fixture reused the previous instance');
    await prepare(widget, 'Archiv', true);
    const before = snapshot(widget);
    check(!key(widget, 'Tab', true).defaultPrevented, 'New widget prevented Shift+Tab');
    widget.tagify.DOM.input.blur();
    await delay(250);
    check(snapshot(widget) === before && widget.submissions.length === 0, 'New widget selected/submitted on Shift+Tab');
  });
  await fixture(['3']);
  window.ddbgoTagifyKeyboardFixture = active;
  document.getElementById('result').dataset.complete = 'true';
  document.getElementById('result').textContent += '\\nManuelle Probe bereit: echte Tab-/Shift+Tab-Tasten verwenden.';
})().catch(error => { document.getElementById('result').textContent += '\\nFAIL: ' + error.stack; });
</script></html>`);
console.log(output);
