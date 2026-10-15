/**
 * Run: node --test web/modules/custom/ddbgo_gin/tests/js/searchable-tabs.test.cjs
 *
 * Runs installed Field Group tab methods and validation with a dependency-free
 * DOM/jQuery adapter. Browser find UI and actual layout need browser testing.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const fieldGroupPath = path.join(__dirname, '../../../../contrib/field_group/formatters/tabs/horizontal-tabs.js');
const validationPath = path.join(__dirname, '../../../../contrib/field_group/js/field_group.tabs_validation.js');
const sourcePath = path.join(__dirname, '../../js/ddbgo_gin.searchable-tabs.js');

function page({supported = true, mobile = false} = {}) {
  const data = new WeakMap();
  const onceMarks = new Map();
  const queued = [];
  let document;
  class Element {
    constructor(tagName, attributes = {}) {
      this.nodeType = 1;
      this.tagName = tagName.toUpperCase();
      this.attributes = new Map(Object.entries(attributes));
      this.children = [];
      this.parentElement = null;
      this.handlers = new Map();
      this.value = '';
      this.style = {
        display: '',
        removeProperty(name) { this[name] = ''; },
      };
      if (supported) this.onbeforematch = null;
      this.classList = {
        contains: name => (this.getAttribute('class') || '').split(/\s+/).includes(name),
        add: name => { if (!this.classList.contains(name)) this.setAttribute('class', `${this.getAttribute('class') || ''} ${name}`.trim()); },
        remove: name => this.setAttribute('class', (this.getAttribute('class') || '').split(/\s+/).filter(value => value !== name).join(' ')),
      };
    }
    get id() { return this.getAttribute('id') || ''; }
    get open() { return this.hasAttribute('open'); }
    set open(value) { if (value) this.setAttribute('open', ''); else this.removeAttribute('open'); }
    get parentNode() { return this.parentElement; }
    get isConnected() { return this === document.html || Boolean(this.parentElement?.isConnected); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    hasAttribute(name) { return this.attributes.has(name); }
    removeAttribute(name) { this.attributes.delete(name); }
    append(...children) {
      for (const child of children) {
        child.remove();
        child.parentElement = this;
        this.children.push(child);
      }
    }
    remove() {
      if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this);
      this.parentElement = null;
    }
    visible() {
      // until-found has a box, while its descendant content remains unrendered.
      if (this.style.display === 'none' || (this.hasAttribute('hidden') && this.getAttribute('hidden') !== 'until-found')) return false;
      for (let ancestor = this.parentElement; ancestor; ancestor = ancestor.parentElement) {
        if (ancestor.style.display === 'none' || ancestor.hasAttribute('hidden')) return false;
        if (ancestor.tagName === 'DETAILS' && !ancestor.open) return false;
      }
      return true;
    }
    matches(selector) {
      if (selector.includes(',')) return selector.split(',').some(part => this.matches(part.trim()));
      if (selector === '[data-horizontal-tabs-panes] > details') return this.tagName === 'DETAILS' && this.parentElement?.hasAttribute('data-horizontal-tabs-panes');
      if (selector.startsWith('#')) return this.id === selector.slice(1);
      if (selector === ':hidden.horizontal-tabs-active-tab') return !this.visible() && this.classList.contains('horizontal-tabs-active-tab');
      if (selector === ':visible:first') return this.visible();
      if (selector === 'details:not(:visible)') return this.tagName === 'DETAILS' && !this.visible();
      let expression = selector.replace(/:first$/, '');
      let excluded = false;
      expression = expression.replace(/:not\(([^)]+)\)/g, (_, condition) => { excluded ||= this.matches(condition); return ''; });
      if (excluded) return false;
      const tag = expression.match(/^[a-z]+/i)?.[0];
      if (tag && this.tagName !== tag.toUpperCase()) return false;
      for (const match of expression.matchAll(/\.([\w-]+)/g)) if (!this.classList.contains(match[1])) return false;
      for (const match of expression.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
        if (!this.hasAttribute(match[1]) || (match[2] !== undefined && this.getAttribute(match[1]) !== match[2])) return false;
      }
      return true;
    }
    closest(selector) {
      for (let current = this; current; current = current.parentElement) if (current.matches(selector)) return current;
      return null;
    }
    descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
    querySelectorAll(selector) {
      if (selector === '.field-group-tabs-wrapper :input') return this.descendants().filter(child => child.tagName === 'INPUT' && child.closest('.field-group-tabs-wrapper'));
      return this.descendants().filter(child => child.matches(selector));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    addEventListener(name, handler) {
      if (!this.handlers.has(name)) this.handlers.set(name, new Set());
      this.handlers.get(name).add(handler);
    }
    removeEventListener(name, handler) { this.handlers.get(name)?.delete(handler); }
    focus() { document.activeElement = this; }
  }
  const element = (tag, attributes = {}) => new Element(tag, attributes);
  const html = element('html');
  document = {
    html,
    documentElement: html,
    activeElement: null,
    createElement: tag => element(tag),
    querySelectorAll: selector => selector === 'html' ? [html] : html.querySelectorAll(selector),
    querySelector: selector => html.querySelector(selector),
    addEventListener: (name, handler) => html.addEventListener(name, handler),
  };
  class JQuery {
    constructor(nodes, previous) {
      this.nodes = nodes.filter(Boolean);
      this.previous = previous;
      this.length = this.nodes.length;
      this.nodes.forEach((node, index) => { this[index] = node; });
    }
    attr(name, value) {
      if (value === undefined) return this[0]?.getAttribute(name);
      this.nodes.forEach(node => node.setAttribute(name, value));
      return this;
    }
    removeAttr(name) { this.nodes.forEach(node => node.removeAttribute(name)); return this; }
    data(name, value) {
      if (value === undefined) return data.get(this[0])?.[name];
      this.nodes.forEach(node => { if (!data.has(node)) data.set(node, {}); data.get(node)[name] = value; });
      return this;
    }
    addClass(name) { this.nodes.forEach(node => node.classList.add(name)); return this; }
    removeClass(name) { this.nodes.forEach(node => node.classList.remove(name)); return this; }
    hasClass(name) { return Boolean(this[0]?.classList.contains(name)); }
    hide() { this.nodes.forEach(node => { node.style.display = 'none'; }); return this; }
    show() { this.nodes.forEach(node => { node.style.display = ''; }); return this; }
    siblings(selector) {
      const result = this.nodes.flatMap(node => node.parentElement.children.filter(sibling => sibling !== node && sibling.matches(selector)));
      return new JQuery(selector.endsWith(':first') ? result.slice(0, 1) : result, this);
    }
    parent() { return new JQuery(this.nodes.map(node => node.parentElement), this); }
    parents(selector) {
      const parents = [];
      for (let current = this[0]?.parentElement; current; current = current.parentElement) if (current.matches(selector)) parents.push(current);
      return new JQuery(parents, this);
    }
    children(selector) { return new JQuery(this.nodes.flatMap(node => node.children.filter(child => child.matches(selector))), this); }
    closest(selector) { return new JQuery(this.nodes.map(node => node.closest(selector)), this); }
    filter(selector) {
      const result = this.nodes.filter(node => node.matches(selector));
      return new JQuery(selector.endsWith(':first') ? result.slice(0, 1) : result, this);
    }
    each(callback) { this.nodes.forEach((node, index) => callback.call(node, index, node)); return this; }
    end() { return this.previous; }
    val(value) { if (value === undefined) return this[0]?.value; this.nodes.forEach(node => { node.value = value; }); return this; }
    remove() { this.nodes.forEach(node => node.remove()); return this; }
    append(value) {
      this.nodes.forEach(node => node.append(value instanceof Element ? value : value instanceof JQuery ? value[0] : element('span', {id: 'active-horizontal-tab'})));
      return this;
    }
    on(name, handler) { this.nodes.forEach(node => node.addEventListener(name, handler)); return this; }
    off(name, handler) { this.nodes.forEach(node => node.removeEventListener(name, handler)); return this; }
    trigger(name) { this.nodes.forEach(node => emit(name, node)); return this; }
  }
  const $ = (value, context = document) => value instanceof JQuery ? value
    : new JQuery(typeof value === 'string' ? context.querySelectorAll(value) : Array.isArray(value) ? value : [value]);
  $.extend = Object.assign;
  const once = (name, selector, context = document) => {
    const nodes = $(selector, context).nodes;
    if (!onceMarks.has(name)) onceMarks.set(name, new WeakSet());
    return nodes.filter(node => {
      if (onceMarks.get(name).has(node)) return false;
      onceMarks.get(name).add(node);
      return true;
    });
  };
  const Drupal = {behaviors: {}, t: value => value, theme: (name, settings) => Drupal.theme[name](settings)};
  const context = vm.createContext({document, Drupal, jQuery: $, once, drupalSettings: {},
    window: {matchMedia: () => ({matches: mobile}), location: {hash: ''}, queueMicrotask: callback => queued.push(callback)},
    requestAnimationFrame: callback => queued.push(callback), queueMicrotask: callback => queued.push(callback), Element,
  });
  const load = filename => vm.runInContext(fs.readFileSync(filename, 'utf8'), context, {filename});
  load(fieldGroupPath);
  load(validationPath);
  const original = {...Drupal.HorizontalTab.prototype};
  Drupal.theme.horizontalTab = () => {
    const item = element('li', {class: 'horizontal-tab-button'});
    const link = element('a', {href: '#'});
    item.append(link);
    return {item: $(item), link: $(link), summary: $(element('span'))};
  };
  load(sourcePath);
  const form = element('form', {class: 'node-form'});
  const sentinel = element('input', {id: 'outside-tabs'});
  html.append(sentinel, form);
  sentinel.focus();
  function group(ids, parent = form, {hiddenIndices = []} = {}) {
    const container = element('div', {class: 'form-type-horizontal-tabs field-group-tabs-wrapper', 'data-horizontal-tabs': ''});
    const list = element('ul', {'data-horizontal-tabs-list': ''});
    const panes = element('div', {'data-horizontal-tabs-panes': ''});
    const input = element('input', {type: 'hidden', class: 'horizontal-tabs-active-tab', hidden: ''});
    container.append(list, panes);
    panes.append(input);
    parent.append(container);
    const items = ids.map((id, index) => {
      const pane = element('details', {id, class: 'horizontal-tabs-pane', open: ''});
      if (hiddenIndices.includes(index)) pane.setAttribute('hidden', 'hidden');
      const control = element('input', {id: `${id}-control`});
      pane.append(control);
      panes.append(pane);
      if (!mobile) {
        pane.tab = new Drupal.HorizontalTab({details: $(pane), title: id});
        list.append(pane.tab.item[0]);
        $(pane).data('horizontalTab', pane.tab);
      }
      pane.control = control;
      return pane;
    });
    if (!mobile) items[0].tab.focus();
    return {container, panes: items, input};
  }
  function emit(name, target) {
    const event = {type: name, target, preventDefault() { this.defaultPrevented = true; }};
    const path = [target];
    // beforematch bubbles; native invalid remains on its input, where Field
    // Group has registered its actual validation callback.
    if (name !== 'invalid') {
      for (let node = target.parentElement; node; node = node.parentElement) path.push(node);
    }
    for (const node of path) {
      for (const [eventName, handlers] of node.handlers) {
        if (eventName.split('.')[0] === name) [...handlers].forEach(handler => handler(event));
      }
    }
    return event;
  }
  const flush = () => { while (queued.length) queued.shift()(); };
  const attach = (fragment = document) => { Drupal.behaviors.ddbgoSearchableTabs?.attach(fragment); flush(); };
  const reveal = pane => { emit('beforematch', pane); pane.removeAttribute('hidden'); flush(); };
  const validate = pane => { Drupal.behaviors.fieldGroupTabsValidation.attach(form); emit('invalid', pane.control); flush(); };
  return {document, Drupal, original, form, sentinel, element, group, attach, reveal, validate, emit, flush, $};
}

const assertInactive = pane => {
  assert.equal(pane.getAttribute('hidden'), 'until-found');
  assert.equal(pane.hasAttribute('data-ddbgo-searchable-tab'), true);
  assert.notEqual(pane.style.display, 'none');
  assert.equal(pane.classList.contains('horizontal-tab-hidden'), true, 'Field Group validation still recognizes an inactive pane');
  assert.equal(pane.tab.item.hasClass('selected'), false);
};
const assertActive = (pane, group) => {
  assert.equal(pane.hasAttribute('hidden'), false);
  assert.notEqual(pane.style.display, 'none');
  assert.equal(pane.classList.contains('horizontal-tab-hidden'), false);
  assert.equal(pane.tab.item.hasClass('selected'), true);
  assert.equal(group.input.value, pane.id);
};

test('native reveal survives browser hidden removal and repeated tab switches', () => {
  const p = page();
  const tabs = p.group(['status', 'information', 'contact']);
  p.attach();
  assertActive(tabs.panes[0], tabs);
  assertInactive(tabs.panes[1]);
  assertInactive(tabs.panes[2]);
  p.reveal(tabs.panes[1]);
  assertActive(tabs.panes[1], tabs);
  assertInactive(tabs.panes[0]);
  assertInactive(tabs.panes[2]);
  tabs.panes[2].tab.focus();
  assertActive(tabs.panes[2], tabs);
  assertInactive(tabs.panes[1]);
  p.reveal(tabs.panes[1]);
  assertActive(tabs.panes[1], tabs);
  assertInactive(tabs.panes[2]);
  assert.equal(p.document.activeElement, p.sentinel, 'revealing changes selection without stealing keyboard focus');
});

test('nested reveal activates outer-to-inner tabs and synchronizes both groups', () => {
  const p = page();
  const outer = p.group(['outer-status', 'outer-europeana']);
  const inner = p.group(['inner-data', 'inner-objects'], outer.panes[1]);
  p.attach();
  const order = [];
  for (const pane of [outer.panes[1], inner.panes[1]]) {
    const focus = pane.tab.focus;
    pane.tab.focus = function (...args) { order.push(pane.id); return focus.apply(this, args); };
  }
  p.reveal(inner.panes[1]);
  assert.deepEqual(order, ['outer-europeana', 'inner-objects']);
  assertActive(outer.panes[1], outer);
  assertActive(inner.panes[1], inner);
  assertInactive(outer.panes[0]);
  assertInactive(inner.panes[0]);
  assert.equal(p.document.activeElement, p.sentinel);
});

test('nested tab reveal opens native details ancestors without hiding their summaries', () => {
  const p = page();
  const outerDetails = p.element('details', {id: 'native-outer'});
  const outerSummary = p.element('summary');
  outerDetails.append(outerSummary);
  p.form.append(outerDetails);
  const outerTabs = p.group(['outer-first', 'outer-match'], outerDetails);
  const innerDetails = p.element('details', {id: 'native-inner'});
  const innerSummary = p.element('summary');
  innerDetails.append(innerSummary);
  outerTabs.panes[1].append(innerDetails);
  const innerTabs = p.group(['inner-first', 'inner-match'], innerDetails);
  p.attach();
  assert.equal(outerDetails.open, false);
  assert.equal(innerDetails.open, false);
  assert.equal(outerDetails.hasAttribute('hidden'), false);
  assert.equal(innerDetails.hasAttribute('hidden'), false);
  p.reveal(innerTabs.panes[1]);
  assert.equal(outerDetails.open, true);
  assert.equal(innerDetails.open, true);
  assertActive(outerTabs.panes[1], outerTabs);
  assertActive(innerTabs.panes[1], innerTabs);
  for (const details of [outerDetails, innerDetails]) {
    assert.equal(details.hasAttribute('hidden'), false);
    assert.equal(details.hasAttribute('data-ddbgo-searchable-tab'), false);
    assert.equal(details.querySelector('summary').visible(), true);
  }
  assert.equal(p.document.activeElement, p.sentinel);
});

test('intentional tabHide stays unavailable and tabShow retains Field Group semantics', () => {
  const p = page();
  const tabs = p.group(['first', 'optional']);
  p.attach();
  assert.equal(tabs.panes[1].tab.tabHide(), tabs.panes[1].tab);
  p.attach();
  assert.equal(tabs.panes[1].tab.item.hasClass('horizontal-tab-hidden'), true);
  assert.equal(tabs.panes[1].style.display, 'none');
  assert.equal(tabs.panes[1].getAttribute('hidden'), null);
  assert.equal(tabs.panes[1].hasAttribute('data-ddbgo-searchable-tab'), false);
  assertActive(tabs.panes[0], tabs);
  p.emit('beforematch', tabs.panes[1]);
  assertActive(tabs.panes[0], tabs);
  assert.equal(tabs.panes[1].tab.item.hasClass('horizontal-tab-hidden'), true, 'a stale reveal handler cannot restore an intentionally hidden tab');
  assert.equal(tabs.panes[1].tab.tabShow(), tabs.panes[1].tab);
  assertActive(tabs.panes[1], tabs);
  assertInactive(tabs.panes[0]);
});

test('unrelated hidden attributes survive initialization and later tab selection', () => {
  const p = page();
  const tabs = p.group(['first', 'hidden-by-other-code', 'searchable'], p.form, {hiddenIndices: [1]});
  p.attach();
  const preexisting = tabs.panes[1];
  assert.equal(preexisting.getAttribute('hidden'), 'hidden');
  assert.equal(preexisting.hasAttribute('data-ddbgo-searchable-tab'), false);
  assert.equal(preexisting.style.display, 'none');
  assert.equal(preexisting.handlers.get('beforematch')?.size || 0, 0);
  const changed = tabs.panes[2];
  assertInactive(changed);
  changed.setAttribute('hidden', 'hidden');
  p.attach(tabs.container);
  assert.equal(changed.getAttribute('hidden'), 'hidden', 'normalization does not overwrite external hiding');
  changed.tab.focus();
  assert.equal(changed.getAttribute('hidden'), 'hidden', 'selection removes only the enhancement-owned until-found value');
  assert.equal(changed.hasAttribute('data-ddbgo-searchable-tab'), false, 'selection clears the ownership marker');
  assert.equal(changed.tab.item.hasClass('selected'), true);
  assert.equal(tabs.input.value, changed.id);
  assert.equal(changed.visible(), false);
});

test('tabs outside node displays and node forms keep original Field Group hiding', () => {
  const p = page();
  const unrelated = p.element('div', {class: 'other-widget'});
  p.document.html.append(unrelated);
  const tabs = p.group(['outside-first', 'outside-second'], unrelated);
  p.attach();
  for (const pane of tabs.panes) {
    assert.equal(pane.hasAttribute('hidden'), false);
    assert.equal(pane.hasAttribute('data-ddbgo-searchable-tab'), false);
    assert.equal(pane.handlers.get('beforematch')?.size || 0, 0);
  }
  assert.equal(tabs.panes[1].style.display, 'none');
  tabs.panes[1].tab.focus();
  assert.equal(tabs.panes[0].style.display, 'none');
  assert.equal(tabs.panes[0].hasAttribute('hidden'), false);
  assert.equal(tabs.panes[0].hasAttribute('data-ddbgo-searchable-tab'), false);
});

test('invalid control reveals nested panes through actual Field Group validation', () => {
  const p = page();
  const outer = p.group(['main', 'secondary']);
  const inner = p.group(['inner-first', 'inner-invalid'], outer.panes[1]);
  p.attach();
  assertInactive(outer.panes[1]);
  assertInactive(inner.panes[1]);
  p.validate(inner.panes[1]);
  assertActive(outer.panes[1], outer);
  assertActive(inner.panes[1], inner);
  assert.equal(p.document.activeElement, p.sentinel, 'browser retains ownership of validation focus');
});

test('repeat attach and AJAX replacement bind each new pane exactly once', () => {
  const p = page();
  const initial = p.group(['first', 'second']);
  p.attach();
  const wrappedFocus = p.Drupal.HorizontalTab.prototype.focus;
  p.attach();
  p.attach(initial.container);
  assert.equal(p.Drupal.HorizontalTab.prototype.focus, wrappedFocus);
  assert.equal(initial.panes[1].handlers.get('beforematch')?.size, 1);
  initial.container.remove();
  const replacement = p.group(['first', 'second']);
  p.attach(replacement.container);
  p.attach(replacement.container);
  p.attach(replacement.panes[1]);
  p.attach(replacement.panes[1].control);
  assert.equal(replacement.panes[1].handlers.get('beforematch')?.size, 1);
  p.reveal(replacement.panes[1]);
  assertActive(replacement.panes[1], replacement);
  assertInactive(replacement.panes[0]);
  const added = p.group(['ajax-first', 'ajax-second'], replacement.panes[1]);
  p.attach(added.container);
  p.reveal(added.panes[1]);
  assertActive(added.panes[1], added);
  assert.equal(p.document.activeElement, p.sentinel);
});

test('unsupported browser and mobile native details retain existing behavior', () => {
  const unsupported = page({supported: false});
  const tabs = unsupported.group(['first', 'second']);
  unsupported.attach();
  assert.equal(unsupported.Drupal.HorizontalTab.prototype.focus, unsupported.original.focus);
  assert.equal(unsupported.Drupal.HorizontalTab.prototype.tabShow, unsupported.original.tabShow);
  assert.equal(unsupported.Drupal.HorizontalTab.prototype.tabHide, unsupported.original.tabHide);
  assert.equal(tabs.panes[1].style.display, 'none');
  assert.equal(tabs.panes[1].hasAttribute('hidden'), false);
  const mobile = page({mobile: true});
  const details = mobile.group(['mobile-first', 'mobile-second']);
  mobile.attach();
  for (const pane of details.panes) {
    assert.equal(pane.hasAttribute('hidden'), false);
    assert.equal(pane.hasAttribute('data-ddbgo-searchable-tab'), false);
    assert.equal(pane.handlers.get('beforematch')?.size || 0, 0);
  }
});
