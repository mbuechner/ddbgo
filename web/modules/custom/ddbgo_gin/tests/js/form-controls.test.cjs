const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');

// Isolated fixture using the installed widgets and styles. No server requests,
// account, form submission or mocked Select2 implementation is needed.
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const output = path.join(os.tmpdir(), 'ddbgo-form-controls-test.html');
const styles = [
  'core/themes/claro/css/base/elements.css',
  'core/themes/claro/css/base/variables.css',
  'core/themes/claro/css/components/form.css',
  'core/themes/claro/css/components/form--text.css',
  'core/themes/claro/css/components/form--select.css',
  'core/themes/claro/css/components/form--boolean.css',
  'libraries/select2/dist/css/select2.css',
  'modules/contrib/select2/css/select2.gin.css',
  'libraries/tagify/dist/tagify.css',
  'modules/contrib/tagify/css/tagify.css',
  'modules/contrib/tagify/css/gin.css',
  'themes/contrib/gin/dist/css/theme/variables.css',
  'themes/contrib/gin/dist/css/theme/font.css',
  'themes/contrib/gin/dist/css/theme/accent.css',
  'themes/contrib/gin/dist/css/base/gin.css',
  'modules/custom/ddbgo_gin/css/ddbgo_gin.form-controls.css',
  'modules/custom/ddbgo_gin/css/ddbgo_gin.frontend-layout.css',
];
const options = '<option></option><option value="one">Archiv</option><option value="two">Bibliothek</option><option value="long">Ein sehr langer Name einer Kultur- oder Wissenseinrichtung mit mehreren Abteilungen</option>';
const field = (id, label, element) => `<div class="form-item"><label class="form-item__label" for="${id}">${label}</label>${element}</div>`;
const select = (id, extra = '') => `<select class="form-element form-element--type-select" id="${id}" ${extra}>${options}</select>`;

fs.writeFileSync(output, `<!doctype html><html lang="de"><meta charset="utf-8"><title>Form control regression</title>
${styles.map(file => `<link rel="stylesheet" href="${url(file)}">`).join('\n')}
<style>
body[data-gin-accent="custom"]{--gin-color-primary-rgb:8,68,65;--gin-bg-app-rgb:246,248,248}
body{margin:0;padding:24px;font:16px/1.5 sans-serif;background:var(--gin-bg-app);color:var(--gin-color-text)}
main{max-width:1100px;margin:auto}form{padding:24px;background:var(--gin-bg-layer);border:1px solid var(--gin-border-color);border-radius:8px}
.form-item{min-width:0}#columns{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,250px),1fr));gap:24px}
/* The fixture's form already supplies the inner spacing of a details panel. */
.gin-frontend form > details.claro-details > .claro-details__wrapper{margin:0;padding:0}
#result{white-space:pre-wrap;overflow-wrap:anywhere}#late{display:none}
</style>
<body data-gin-accent="custom"><main><h1>Formularfelder</h1><form onsubmit="return false">
${field('single', 'Titel (Select2)', select('single'))}
${field('text', 'Vorname', '<input class="form-element" id="text" size="30">')}
${field('native', 'Native Auswahl', select('native'))}
${field('multiple', 'Mehrfachauswahl (Select2)', select('multiple', 'multiple'))}
${field('tags', 'Tags (Tagify)', '<input id="tags" class="form-element" value="Archiv">')}
${field('note', 'Bemerkung', '<textarea class="form-element" id="note" rows="3"></textarea>')}
<div id="columns">
${field('disabled', 'Deaktiviert', select('disabled', 'disabled'))}
${field('error', 'Fehler', select('error', 'aria-invalid="true"'))}
${field('empty', 'Leere Mehrfachauswahl', select('empty', 'multiple'))}
</div>
${field('date', 'Datum', '<input class="form-element" type="date" id="date">')}
<label><input class="form-boolean" type="checkbox" id="check"> Checkbox</label>
<div id="late">${field('ajax', 'Nachgeladen', select('ajax'))}</div>
</form><pre id="result">pending</pre></main>
<script src="${url('core/assets/vendor/jquery/jquery.min.js')}"></script>
<script src="${url('libraries/select2/dist/js/select2.full.min.js')}"></script>
<script src="${url('libraries/tagify/dist/tagify.js')}"></script>
<script>
const results=[];
const check=(ok,message)=>{if(!ok)throw Error(message);results.push(message)};
const el=id=>document.getElementById(id);
const widget=id=>el(id).nextElementSibling.querySelector('.select2-selection');
const rect=element=>element.getBoundingClientRect();
const same=(a,b)=>Math.abs(a-b)<1;
const mode=new URL(location.href).searchParams.get('mode') || 'default';
// Exercise both the contrib Gin skin and the more specific frontend details CSS.
if(mode==='frontend') {
  document.body.classList.add('gin-frontend');
  jQuery('form').wrapInner('<details class="claro-details" open><div class="claro-details__wrapper"></div></details>');
}
const initialize=id=>jQuery('#'+id).select2({theme:mode==='gin'?'gin':'default',width:'50%',placeholder:id==='empty'?'- Nicht festgelegt/ausgew\u00e4hlt -':'Bitte ausw\u00e4hlen',allowClear:id!=='empty'});
(async()=>{try {
['single','multiple','disabled','error','empty','ajax'].forEach(initialize);
const tags=new Tagify(el('tags'),{whitelist:['Archiv','Bibliothek']});
tags.DOM.scope.classList.add('form-element');
// The Drupal widget transfers these classes to the generated Tagify control.
jQuery('#multiple').val(['one']).trigger('change');
// Let the widgets finish measuring and hiding their original controls.
await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
// Gin's skin animates dimensions; measure the settled layout.
await new Promise(resolve=>setTimeout(resolve,300));
function checkLayout() {
  const reference=rect(el('text'));
  for(const id of ['single','multiple']) {
    check(same(rect(widget(id)).width,reference.width), id+': same column width');
  }
  for(const id of ['single','empty','disabled','error']) {
    check(same(rect(widget(id)).height,reference.height), id+': same empty/single control height');
  }
  check(same(rect(widget('multiple')).height,reference.height),'Selected multiple control has common single-row height');
  check(same(rect(el('native')).height,reference.height),'Native select has common height');
  check(same(rect(el('note')).width,reference.width),'Textarea has common width');
  check(same(rect(tags.DOM.scope).width,reference.width),'Tagify has common width');
  check(same(rect(tags.DOM.scope).height,reference.height),'Tagify has common single-row height');
  check(rect(el('note')).height>reference.height,'Textarea keeps multiple lines');
  check(rect(el('check')).width<reference.width,'Checkbox keeps intrinsic size');
  check(document.documentElement.scrollWidth<=innerWidth,'No horizontal page overflow');
}
checkLayout();
check(getComputedStyle(widget('single'),'::after').content==='""','Single selection has decorative arrow');
check(getComputedStyle(widget('multiple'),'::after').content==='""','Multiple selection has decorative arrow');
check(getComputedStyle(widget('multiple'),'::after').pointerEvents==='none','Arrow does not intercept input');
check(el('single').classList.contains('select2-hidden-accessible') && getComputedStyle(el('single')).clipPath==='inset(50%)','Native select retains clipping after enhancement');
check(el('disabled').disabled && widget('disabled').getAttribute('aria-disabled')==='true','Disabled semantics retained');
check(getComputedStyle(widget('error')).borderColor!==getComputedStyle(widget('single')).borderColor,'Validation error is visible on replacement');
jQuery('#single').select2('open');
check(widget('single').getAttribute('aria-expanded')==='true','Opening updates expanded state');
const search=document.querySelector('.select2-search--dropdown input');
search.focus();search.value='Bibliothek';jQuery(search).trigger('input');
check(document.querySelector('.select2-results').textContent.includes('Bibliothek')&&!document.querySelector('.select2-results').textContent.includes('Archiv'),'Dropdown search still filters');
jQuery(search).trigger(jQuery.Event('keydown',{which:13,keyCode:13}));
check(el('single').value==='two','Keyboard selects result');
jQuery('#single').select2('open');
jQuery(document.querySelector('.select2-search--dropdown input')).trigger(jQuery.Event('keydown',{which:27,keyCode:27}));
check(widget('single').getAttribute('aria-expanded')==='false','Escape closes selection');
widget('single').focus();
check(getComputedStyle(widget('single')).outlineStyle!=='none','Keyboard focus remains visible');
widget('single').querySelector('.select2-selection__clear').dispatchEvent(new MouseEvent('mousedown',{bubbles:true}));
check(!el('single').value,'Clear button still clears');
jQuery('#single').select2('close');
jQuery('#multiple').val(['one','two','long']).trigger('change');
check(rect(widget('multiple')).height>=rect(el('text')).height,'Multiple values can grow vertically');
const longChoice=widget('multiple').querySelectorAll('.select2-selection__choice')[2];
check(getComputedStyle(longChoice).whiteSpace==='normal','Long choices wrap rather than being truncated');
widget('multiple').querySelector('.select2-selection__choice__remove').click();
check(el('multiple').selectedOptions.length===2,'Individual remove button still works');
check(getComputedStyle(widget('multiple').querySelector('.select2-search__field')).outlineStyle==='none','Inline typing area uses the outer control focus ring');
jQuery('#multiple').select2('close');
el('late').style.display='block';
check(same(rect(widget('ajax')).width,rect(el('text')).width),'Select initialized in hidden tab fills column when shown');
window.checkLayout=checkLayout;
el('result').textContent='PASS: '+results.join(' | ');
} catch(error) {el('result').textContent='FAIL: '+error.stack;}})();
</script></html>`);
console.log(output);
