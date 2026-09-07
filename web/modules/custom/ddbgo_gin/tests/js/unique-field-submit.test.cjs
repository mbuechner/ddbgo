/**
 * Build a standalone browser regression fixture using the installed Drupal
 * beforeSend, formSingleSubmit and Unique Field AJAX change handler.
 *
 * Run with Node, then open the printed HTML path in a browser. Requests and
 * response timing are controlled locally; no server receives a submission.
 */
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const web = path.resolve(__dirname, '../../../../..');
const read = (file) => fs.readFileSync(path.join(web, file), 'utf8');
const script = (file) => `<script src="${pathToFileURL(path.join(web, file)).href}"></script>`;
function extract(file, marker) {
  const source = read(file);
  const start = source.indexOf(marker);
  const end = source.indexOf('\n  };', start);
  if (start < 0 || end < 0) throw new Error(`Drupal callback changed: ${marker}`);
  return source.slice(start, end + 5);
}
const beforeSend = extract('core/misc/ajax.js', '  Drupal.Ajax.prototype.beforeSend = function');
const singleSubmit = extract('core/misc/form.js', '  Drupal.behaviors.formSingleSubmit = {');
const output = path.join(os.tmpdir(), 'ddbgo-unique-field-submit-test.html');
fs.writeFileSync(output, `<!doctype html><meta charset="utf-8"><title>Unique Field AJAX submission regression</title>
<div id="fixtures"></div><pre id="result">pending</pre>
${script('core/assets/vendor/jquery/jquery.min.js')}
${script('core/misc/jquery.form.js')}
${script('core/assets/vendor/once/once.min.js')}
<script>
const notices=[];
window.Drupal={behaviors:{},Ajax:function(){},ajax:{instances:[]},t:s=>s,announce:s=>notices.push(s)};
window.drupalSettings={unique_field_ajax:[{id:'#unique-title input'},{id:'#unique-field_ddburi input'}]};
const $=jQuery;
${beforeSend}
${singleSubmit}
</script>
${script('modules/contrib/unique_field_ajax/js/keyupdelay.js')}
${script('modules/custom/ddbgo_gin/js/ddbgo_gin.unique-field-submit.js')}
<script>
(async()=>{
const results=[];
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const check=(ok,message)=>{if(!ok)throw new Error(message)};
const fixtures=document.getElementById('fixtures');
const submissions=new WeakMap();
Drupal.behaviors.formSingleSubmit.attach();
document.addEventListener('submit',event=>{
 if(event.defaultPrevented)return;
 event.preventDefault();
 const entries=new FormData(event.target,event.submitter);
 const log=submissions.get(event.target)||[];log.push(entries);submissions.set(event.target,log);
});
const count=form=>(submissions.get(form)||[]).length;
function makeForm({uri=false,extra=false,unique=true}={}) {
 const form=document.createElement('form');form.method='post';
 form.innerHTML='<div '+(unique?'id="unique-title"':'')+'><input name="title[0][value]" value="Test Aggregator" required></div>'
  +(uri?'<div id="unique-field_ddburi"><input name="field_ddburi[0][value]" value="https://example.test/kwe"></div>':'')
  +(extra?'<input name="other" value="Required value" required>':'')
  +'<button id="save" data-drupal-selector="edit-submit" type="submit" name="op" value="Speichern">Speichern</button>';
 fixtures.replaceChildren(form);return form;
}
function attach(){Drupal.behaviors.ddbgoUniqueFieldSubmit.attach();}
function setup(form) {
 drupalSettings.unique_field_ajax=['#unique-title input','#unique-field_ddburi input'].filter(id=>form.querySelector(id)).map(id=>({id}));
 Drupal.behaviors.unique_field_ajax.attach(form,{});
 const requests=[];
 for(const input of form.querySelectorAll('#unique-title input,#unique-field_ddburi input')) {
  const ajax={element:input,$form:$(form),elementSettings:{event:'finishedinput'},ajaxing:false,progress:false,options:{}};
  ajax.options.error=function(){ajax.ajaxing=false;input.disabled=false;};
  $(input).on('finishedinput',()=>{
   if(ajax.ajaxing)return;
   ajax.ajaxing=true;
   Drupal.Ajax.prototype.beforeSend.call(ajax,null,{});
  });
  Drupal.ajax.instances.push(ajax);requests.push(ajax);
 }
 attach();attach();return requests;
}
function start(ajax){ajax.element.dispatchEvent(new Event('change',{bubbles:true}));}
async function finish(ajax,{replace=false,commandDelay=0}={}) {
 if(replace){const fresh=ajax.element.cloneNode(true);fresh.disabled=false;ajax.element.replaceWith(fresh);attach();}
 else ajax.element.disabled=false;
 await delay(commandDelay);ajax.ajaxing=false;
}
const saved=(form,name)=>submissions.get(form)?.at(-1).get(name);
async function test(name,fn){await fn();results.push(name);}
try {
 await test('pending check, double click, original submitter and Drupal single-submit',async()=>{
  const form=makeForm(),[ajax]=setup(form),button=form.querySelector('button');start(ajax);await delay(10);
  check(ajax.element.disabled,'Trigger not disabled');form.requestSubmit(button);form.requestSubmit(button);await delay(20);
  check(count(form)===0,'Saved during validation');check(!form.hasAttribute('data-drupal-form-submit-last'),'Premature single-submit marker');
  await finish(ajax);await delay(90);
  check(count(form)===1,'Expected exactly one save');check(saved(form,'title[0][value]')==='Test Aggregator','Title lost');check(saved(form,'op')==='Speichern','Submitter lost');check(!button.hasAttribute('aria-disabled'),'Button state not restored');
 });
 await test('change timer queued by the direct input-to-save click',async()=>{
  const form=makeForm(),[ajax]=setup(form);start(ajax);form.requestSubmit(form.querySelector('button'));await delay(20);
  check(count(form)===0&&ajax.ajaxing,'Queued validation bypassed');await finish(ajax);await delay(90);check(count(form)===1,'Queued save not resumed');
 });
 await test('multiple checks, field replacement, delayed response commands',async()=>{
  const form=makeForm({uri:true}),requests=setup(form);requests.forEach(start);await delay(10);form.requestSubmit(form.querySelector('button'));
  await finish(requests[0],{replace:true});await delay(70);check(count(form)===0,'Saved while another check runs');
  const finishing=finish(requests[1],{replace:true,commandDelay:100});Drupal.ajax.instances[Drupal.ajax.instances.indexOf(requests[1])]=null;await delay(70);check(count(form)===0,'Saved before response commands finished');await finishing;await delay(90);
  check(count(form)===1,'Concurrent checks not resumed');check(saved(form,'field_ddburi[0][value]')==='https://example.test/kwe','DDB URI lost');check(saved(form,'title[0][value]')==='Test Aggregator','Title lost after replacement');
 });
 await test('keyboard/requestSubmit without a submitter',async()=>{
  const form=makeForm();setup(form);form.requestSubmit();await delay(30);check(count(form)===1,'Keyboard save lost');check(saved(form,'title[0][value]')==='Test Aggregator','Keyboard value lost');
 });
 await test('native required validation is repeated and retry remains possible',async()=>{
  const form=makeForm({extra:true}),[ajax]=setup(form);start(ajax);await delay(10);form.requestSubmit(form.querySelector('button'));form.elements.other.value='';await finish(ajax);await delay(90);
  check(count(form)===0,'Native validation bypassed');form.elements.other.value='Corrected';form.requestSubmit(form.querySelector('button'));await delay(30);check(count(form)===1,'Retry blocked');
 });
 await test('failed request cancels automatic save and allows manual retry',async()=>{
  const form=makeForm(),[ajax]=setup(form);start(ajax);await delay(10);form.requestSubmit(form.querySelector('button'));ajax.options.error();await delay(90);
  check(count(form)===0,'Saved after failed validation');check(notices.length>0,'Failure not announced');check(ajax.element.value==='Test Aggregator','Error lost input');form.requestSubmit(form.querySelector('button'));await delay(30);check(count(form)===1,'Retry after error blocked');
 });
 await test('reset cancels a queued save',async()=>{
  const form=makeForm(),[ajax]=setup(form);start(ajax);await delay(10);form.requestSubmit(form.querySelector('button'));form.reset();await finish(ajax);await delay(90);check(count(form)===0,'Saved after reset');
 });
 await test('replaced submitter keeps the selected action',async()=>{
  const form=makeForm(),[ajax]=setup(form),button=form.querySelector('button');start(ajax);await delay(10);form.requestSubmit(button);const replacement=button.cloneNode(true);replacement.removeAttribute('aria-disabled');button.replaceWith(replacement);await finish(ajax);await delay(90);check(count(form)===1&&saved(form,'op')==='Speichern','Replacement action lost');
 });
 await test('unrelated forms submit immediately',async()=>{
  const form=makeForm({unique:false});form.requestSubmit(form.querySelector('button'));check(count(form)===1,'Unrelated form delayed');
 });
 await test('unrelated requests do not delay this form',async()=>{
  const form=makeForm();setup(form);Drupal.ajax.instances.push({element:document.createElement('input'),ajaxing:true});form.requestSubmit(form.querySelector('button'));await delay(30);check(count(form)===1,'Another form blocked submission');
 });
 document.getElementById('result').textContent='PASS: '+results.length+' regression cases; '+results.join(' | ');
}catch(error){document.getElementById('result').textContent='FAIL after '+results.length+' cases: '+error.message;}
})();
</script>`);
console.log(output);
