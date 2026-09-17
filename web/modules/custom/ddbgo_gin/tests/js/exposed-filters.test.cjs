/**
 * Browser regression fixture using the installed Tagify library and Drupal
 * select widget. Run with Node and open the printed HTML file in a browser.
 * Submissions are recorded locally; no request or navigation takes place.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const script = file => `<script src="${url(file)}"></script>`;
const output = process.argv[2] || path.join(os.tmpdir(), 'ddbgo-exposed-filters-test.html');
const behavior = fs.readFileSync(path.join(web, 'modules/custom/ddbgo_gin/js/ddbgo_gin.exposed-filters.js'), 'utf8');

fs.writeFileSync(output, `<!doctype html><html lang="de"><meta charset="utf-8">
<title>Tag filter submission regression</title>
<link rel="stylesheet" href="${url('libraries/tagify/dist/tagify.css')}">
<div id="fixtures"></div><pre id="result">pending</pre>
<script>window.drupalSettings={tagify_select:{information_message:{limit_tag:'Limit',no_matching_suggestions:'None'}}};</script>
${script('core/misc/drupal.js')}
${script('core/assets/vendor/once/once.min.js')}
${script('core/assets/vendor/sortable/Sortable.min.js')}
${script('libraries/tagify/dist/tagify.js')}
${script('modules/contrib/tagify/js/tagify.helpers.js')}
${script('modules/contrib/tagify/js/tagify.js')}
<script>${behavior}</script>
<script>
(async () => {
  const results = [];
  const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
  const check = (ok, message) => { if (!ok) throw new Error(message); };
  const selection = select => Array.from(select.selectedOptions, option => option.value).sort();
  async function fixture(values, duration, attachFirst = false) {
    const form = document.createElement('form');
    form.innerHTML = '<input name="query" value="Archiv"><select multiple name="field_bestandstags[]" '
      + 'class="tagify-select-widget" data-cardinality="-1" data-identifier="test-tags" '
      + 'data-match-limit="0" data-match-operator="0" data-ddbgo-tag-auto-submit>'
      + ['1','2','3'].map(value => '<option value="'+value+'" '+(values.includes(value)?'selected':'')+'>Tag '+value+'</option>').join('')
      + '</select><button type="submit" data-ddbgo-tag-auto-submit-click>Apply</button>';
    document.getElementById('fixtures').replaceChildren(form);
    const select = form.querySelector('select');
    const submissions = [];
    form.addEventListener('submit', event => {
      event.preventDefault();
      const data = new FormData(form);
      check(data.get('query') === 'Archiv', 'Other filters were lost');
      submissions.push(data.getAll('field_bestandstags[]').sort());
    });
    if (attachFirst) Drupal.behaviors.ddbgoTagExposedFilters.attach(form);
    Drupal.behaviors.tagifySelect.attach(form);
    const tagify = form.querySelector('input.tagify-select-widget').__tagify;
    tagify.CSSVars.tagHideTransition = duration;
    Drupal.behaviors.ddbgoTagExposedFilters.attach(form);
    Drupal.behaviors.ddbgoTagExposedFilters.attach(form);
    await delay(150);
    return { form, select, tagify, submissions };
  }
  async function test(name, callback) {
    try { await callback(); results.push('PASS: '+name); }
    catch (error) { results.push('FAIL: '+name+' — '+error.message); }
    document.getElementById('result').textContent = results.join('\\n');
  }
  for (const duration of [0, 300, 700]) {
    for (const count of [1, 2, 3]) {
      await test('Remove from '+count+' tags, animation '+duration+'ms', async () => {
        const values = ['1','2','3'].slice(0, count);
        const { form, submissions } = await fixture(values, duration);
        submissions.length = 0;
        form.querySelector('.tagify__tag__removeBtn').click();
        await delay(duration + 200);
        const expected = values.slice(1);
        check(submissions.length === 1, 'Expected one submit, got '+JSON.stringify(submissions));
        check(JSON.stringify(submissions[0]) === JSON.stringify(expected), 'Submitted stale tags: '+JSON.stringify(submissions));
      });
    }
  }
  for (const attachFirst of [false, true]) {
    await test('No initial/no-op submit; attach first: '+attachFirst, async () => {
      const { select, submissions } = await fixture(['1','2'], 300, attachFirst);
      select.dispatchEvent(new Event('change', { bubbles: true }));
      await delay(150);
      check(submissions.length === 0, 'Unchanged selection was submitted');
    });
  }
  await test('Add, remove and re-add; duplicate change events', async () => {
    const { tagify, select, submissions } = await fixture(['1'], 300);
    submissions.length = 0;
    tagify.addTags([{ value:'2', text:'Tag 2' }]);
    await delay(200);
    tagify.removeTags('2');
    await delay(500);
    tagify.addTags([{ value:'2', text:'Tag 2' }]);
    await delay(200);
    select.dispatchEvent(new Event('change', { bubbles:true }));
    await delay(50);
    check(JSON.stringify(submissions) === JSON.stringify([['1','2'],['1'],['1','2']]), JSON.stringify(submissions));
    check(selection(select).join() === '1,2', 'Final selection is wrong');
  });
  document.getElementById('result').dataset.complete = 'true';
})();
</script></html>`);
console.log(output);
