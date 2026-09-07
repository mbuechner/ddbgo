const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { pathToFileURL } = require('node:url');
const web = path.resolve(__dirname, '../../../../..');
const url = file => pathToFileURL(path.join(web, file)).href;
const output = path.join(os.tmpdir(), 'ddbgo-form-help-test.html');
// No server requests: exercise the actual behavior and CSS in a browser.
fs.writeFileSync(output, `<!doctype html><meta charset="utf-8"><title>Form tooltip regression</title>
<link rel="stylesheet" href="${url('themes/contrib/gin/dist/css/components/description_toggle.css')}">
<link rel="stylesheet" href="${url('modules/custom/ddbgo_gin/css/ddbgo_gin.form-help.css')}">
<style>body{font:16px sans-serif;margin:16px}.sample{margin-block:20px}#edge{position:fixed;right:8px;bottom:8px}</style>
<div id="fixtures"></div><pre id="result">pending</pre>
<script src="${url('core/assets/vendor/once/once.min.js')}"></script>
<script>window.Drupal={behaviors:{}};</script>
<script src="${url('modules/custom/ddbgo_gin/js/ddbgo_gin.form-help.js')}"></script>
<script>
(async()=>{
const results=[];
const check=(ok,message)=>{if(!ok)throw new Error(message);results.push(message)};
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
const behavior=Drupal.behaviors.ddbgoFormHelp;
const fixtures=document.getElementById('fixtures');
const markup=id=>'<button type="button" class="ddbgo-help-toggle" aria-label="Weitere Informationen" aria-describedby="'+id+'"><span aria-hidden="true">?</span></button><div id="'+id+'" role="tooltip" class="ddbgo-help-tooltip"><span class="ddbgo-help-tooltip__arrow" aria-hidden="true"></span><div class="ddbgo-help-tooltip__content">Hilfetext mit <em>Formatierung</em> und einer längeren Erklärung zum Eingabefeld.</div></div>';
fixtures.innerHTML='<div class="sample help-icon ddbgo-form-help"><label style="width:100%">Name des Dienstes</label> '+markup('first')+'</div><details><summary>Geschlossene Gruppe '+markup('group').replace('</button>','</button></summary>')+'</details><div id="edge">'+markup('edge-tip')+'</div><input id="outside">';
const button=fixtures.querySelector('button');
const tip=document.getElementById('first');
check(!tip.hidden && tip.textContent.includes('Hilfetext'), 'Description available before JS');
behavior.attach(document);behavior.attach(document);
check(tip.hidden, 'Tooltip initially closed');
const label=fixtures.querySelector('label').getBoundingClientRect();
const icon=button.getBoundingClientRect();
check(icon.left>=label.right && icon.top<label.bottom, 'Icon stays beside full-width label');
check(getComputedStyle(button).cursor==='default' && getComputedStyle(button.firstElementChild).cursor==='default', 'Normal cursor on icon and button');
button.dispatchEvent(new MouseEvent('mouseenter'));
check(!tip.hidden && tip.getBoundingClientRect().width>0, 'Hover opens tooltip');
button.dispatchEvent(new MouseEvent('mouseleave'));
tip.dispatchEvent(new MouseEvent('mouseenter'));
await delay(160);
check(!tip.hidden, 'Pointer can move into tooltip');
tip.dispatchEvent(new MouseEvent('mouseleave'));
await delay(160);
check(tip.hidden, 'Leaving tooltip closes it');
button.focus();
check(!tip.hidden && document.activeElement===button, 'Focus opens without moving focus');
document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
check(tip.hidden && document.activeElement===button, 'Escape dismisses and retains focus');
button.click();check(!tip.hidden, 'Click opens after Escape');
button.click();check(tip.hidden, 'Second click closes; attach is idempotent');
button.click();
document.getElementById('outside').dispatchEvent(new PointerEvent('pointerdown',{bubbles:true}));
check(tip.hidden, 'Outside interaction dismisses');
button.blur();button.focus();document.getElementById('outside').focus();await delay(160);
check(tip.hidden, 'Moving focus away closes');
const details=fixtures.querySelector('details');
const group=details.querySelector('button');group.focus();
const groupTip=document.getElementById('group');
check(!details.open && !groupTip.hidden && groupTip.getBoundingClientRect().height>0, 'Tooltip is visible on closed details');
group.click();check(!details.open, 'Help click does not toggle details');
const edge=document.querySelector('#edge button');edge.focus();
const rect=document.getElementById('edge-tip').getBoundingClientRect();
check(rect.left>=0 && rect.right<=document.documentElement.clientWidth && rect.top>=0 && rect.bottom<=innerHeight, 'Tooltip fits viewport at bottom right');
const ajax=document.createElement('div');ajax.innerHTML=markup('ajax-tip');fixtures.append(ajax);behavior.attach(ajax);
ajax.querySelector('button').focus();check(!document.getElementById('ajax-tip').hidden,'AJAX-added help works');
behavior.detach(ajax,{},'unload');check(document.getElementById('ajax-tip').hidden,'AJAX removal closes active tooltip');
// Simulate browsers without the Popover API (selector must not be evaluated).
const fallback=document.createElement('div');fallback.innerHTML=markup('fallback');fixtures.append(fallback);
const fallbackTip=document.getElementById('fallback');fallbackTip.showPopover=undefined;fallbackTip.hidePopover=undefined;
behavior.attach(fallback);fallback.querySelector('button').focus();check(!fallbackTip.hidden && !fallbackTip.hasAttribute('popover'), 'Fixed-position fallback opens');
document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));check(fallbackTip.hidden,'Fallback Escape closes');
document.getElementById('result').textContent='PASS: '+results.join(' | ');
})().catch(error=>{document.getElementById('result').textContent='FAIL: '+error.stack});
</script>`);
console.log(output);
