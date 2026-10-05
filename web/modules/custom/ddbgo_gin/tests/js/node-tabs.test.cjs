const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const script = fs.readFileSync(path.join(__dirname, '../../js/ddbgo_gin.node-tabs.js'), 'utf8');
const fieldGroup = fs.readFileSync(path.join(__dirname, '../../../../contrib/field_group/formatters/tabs/horizontal-tabs.js'), 'utf8');
const validation = fs.readFileSync(path.join(__dirname, '../../../../contrib/field_group/js/field_group.tabs_validation.js'), 'utf8');

// Dependency-free DOM/jQuery adapter running Field Group's actual constructor,
// focus/tabShow/tabHide and invalid handlers. Browser layout is not simulated.
function page({ ids = ['edit-group-status', 'edit-group-informationen'], hash = '', mobile = false,
  errors = false, destinations = ['/kwe/example', '/node/42/edit'], extraLinks = 0 } = {}) {
  const listeners = new Map();
  const queued = [];
  const observers = [];
  const queries = { documentLinks: 0, fragmentLinks: 0, panes: 0, urls: 0 };
  const data = new WeakMap();
  const onceMarks = new Map();
  let document;
  class Element {
    constructor(tag, attributes = {}) {
      this.tagName = tag.toUpperCase();
      this.attributes = new Map(Object.entries(attributes));
      this.children = [];
      this.parentElement = null;
      this.handlers = new Map();
      this.hidden = false;
      this.open = false;
      this.value = '';
      this.writes = 0;
      this.classList = {
        contains: name => this.classes().includes(name),
        add: name => this.setClass([...new Set([...this.classes(), name])].join(' ')),
        remove: name => this.setClass(this.classes().filter(value => value !== name).join(' ')),
      };
    }
    classes() { return (this.attributes.get('class') || '').split(/\s+/).filter(Boolean); }
    setClass(value) {
      const oldValue = this.attributes.get('class') || '';
      this.attributes.set('class', value);
      for (const observer of observers) {
        const options = observer.targets.get(this);
        if (options?.attributes && (!options.attributeFilter || options.attributeFilter.includes('class'))) {
          observer.records.push({ target: this, oldValue: options.attributeOldValue ? oldValue : null });
        }
      }
    }
    get id() { return this.attributes.get('id') || ''; }
    get href() { return new URL(this.attributes.get('href'), document.baseURI).href; }
    set href(value) { this.attributes.set('href', value); this.writes++; }
    get isConnected() { return this === document.html || !!this.parentElement?.isConnected; }
    append(...children) {
      for (const child of children) {
        child.remove();
        child.parentElement = this;
        this.children.push(child);
      }
      return this;
    }
    remove() {
      if (this.parentElement) {
        this.parentElement.children = this.parentElement.children.filter(child => child !== this);
        this.parentElement = null;
      }
    }
    matches(selector) {
      if (selector.includes(',')) return selector.split(',').some(value => this.matches(value.trim()));
      if (selector === '[data-horizontal-tabs-panes] > details') {
        return this.tagName === 'DETAILS' && this.parentElement?.attributes.has('data-horizontal-tabs-panes');
      }
      if (selector === ':visible:first') return !this.hidden;
      if (selector === ':hidden.horizontal-tabs-active-tab') return this.hidden && this.classList.contains('horizontal-tabs-active-tab');
      if (selector === 'details:not(:visible)') return this.tagName === 'DETAILS' && this.hidden;
      if (selector === '.horizontal-tabs-pane:not(.horizontal-tab-hidden):first') {
        return this.classList.contains('horizontal-tabs-pane') && !this.classList.contains('horizontal-tab-hidden');
      }
      if (selector.startsWith('#')) return this.id === selector.slice(1);
      const tag = selector.match(/^[a-z]+/i)?.[0];
      if (tag && this.tagName !== tag.toUpperCase()) return false;
      for (const match of selector.matchAll(/\.([\w-]+)/g)) {
        if (!this.classList.contains(match[1])) return false;
      }
      for (const match of selector.matchAll(/\[([\w-]+)(?:="([^"]*)")?\]/g)) {
        if (!this.attributes.has(match[1]) || (match[2] !== undefined && this.attributes.get(match[1]) !== match[2])) return false;
      }
      return true;
    }
    closest(selector) {
      for (let current = this; current; current = current.parentElement) {
        if (current.matches(selector)) return current;
      }
      return null;
    }
    descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
    querySelectorAll(selector) {
      if (selector === 'a[href]') queries.fragmentLinks++;
      if (selector === '[data-horizontal-tabs-panes] > details') queries.panes++;
      if (selector === '.field-group-tabs-wrapper :input') {
        return this.descendants().filter(child => child.tagName === 'INPUT' && child.closest('.field-group-tabs-wrapper'));
      }
      return this.descendants().filter(child => child.matches(selector));
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  }
  const element = (tag, attributes) => new Element(tag, attributes);
  document = {
    baseURI: 'https://ddbgo.test/node/42/edit',
    html: element('html'),
    querySelector: selector => document.html.querySelector(selector),
    querySelectorAll(selector) {
      if (selector === 'a[href]') queries.documentLinks++;
      return document.html.descendants().filter(child => child.matches(selector));
    },
    addEventListener(name, listener) {
      if (!listeners.has(name)) listeners.set(name, []);
      listeners.get(name).push(listener);
    },
  };

  class JQuery {
    constructor(nodes, previous) {
      this.nodes = nodes;
      this.previous = previous;
      this.length = nodes.length;
      nodes.forEach((node, index) => { this[index] = node; });
    }
    attr(name, value) {
      if (value === undefined) return this[0]?.attributes.get(name);
      this.nodes.forEach(node => name === 'class' ? node.setClass(value) : node.attributes.set(name, String(value)));
      return this;
    }
    data(name, value) {
      if (value === undefined) return data.get(this[0])?.[name];
      this.nodes.forEach(node => { if (!data.has(node)) data.set(node, {}); data.get(node)[name] = value; });
      return this;
    }
    addClass(name) { this.nodes.forEach(node => node.classList.add(name)); return this; }
    removeClass(name) { this.nodes.forEach(node => node.classList.remove(name)); return this; }
    hasClass(name) { return this[0]?.classList.contains(name); }
    hide() { this.nodes.forEach(node => { node.hidden = true; }); return this; }
    show() { this.nodes.forEach(node => { node.hidden = false; }); return this; }
    siblings(selector) { return new JQuery(this.nodes.flatMap(node => node.parentElement.children.filter(sibling => sibling !== node && sibling.matches(selector))), this); }
    parent() { return new JQuery(this.nodes.map(node => node.parentElement), this); }
    parents(selector) {
      const parents = [];
      for (let node = this[0]?.parentElement; node; node = node.parentElement) if (node.matches(selector)) parents.push(node);
      return new JQuery(parents, this);
    }
    children(selector) { return new JQuery(this.nodes.flatMap(node => node.children.filter(child => child.matches(selector))), this); }
    closest(selector) { return new JQuery(this.nodes.map(node => node.closest(selector)).filter(Boolean), this); }
    filter(selector) { const result = this.nodes.filter(node => node.matches(selector)); return new JQuery(selector.endsWith(':first') ? result.slice(0, 1) : result, this); }
    each(callback) { this.nodes.forEach((node, index) => callback.call(node, index, node)); return this; }
    end() { return this.previous; }
    val(value) { this.nodes.forEach(node => { node.value = value; }); return this; }
    remove() { this.nodes.forEach(node => node.remove()); return this; }
    append(value) {
      this.nodes.forEach(node => node.append(value instanceof Element ? value : element('span', { id: 'active-horizontal-tab' })));
      return this;
    }
    on(name, callback) {
      this.nodes.forEach(node => { if (!node.handlers.has(name)) node.handlers.set(name, []); node.handlers.get(name).push(callback); });
      return this;
    }
    off(name, callback) {
      this.nodes.forEach(node => node.handlers.set(name, (node.handlers.get(name) || []).filter(handler => handler !== callback)));
      return this;
    }
    trigger() { return this; }
  }
  const $ = (value, context = document) => value instanceof JQuery ? value
    : new JQuery(typeof value === 'string' ? context.querySelectorAll(value) : Array.isArray(value) ? value : [value]);
  $.extend = Object.assign;
  const once = (name, selector, context = document) => {
    let nodes;
    if (selector === 'html') nodes = context === document ? [document.html] : [];
    else nodes = $(selector, context).nodes;
    if (!onceMarks.has(name)) onceMarks.set(name, new Set());
    return nodes.filter(node => {
      if (onceMarks.get(name).has(node)) return false;
      onceMarks.get(name).add(node);
      return true;
    });
  };
  class MutationObserver {
    constructor(callback) { this.callback = callback; this.targets = new Map(); this.records = []; observers.push(this); }
    observe(target, options) { this.targets.set(target, options); }
    disconnect() { this.targets.clear(); this.records = []; }
  }
  const Drupal = { behaviors: {}, t: value => value, theme: (name, settings) => Drupal.theme[name](settings) };
  const settings = { ddbgoNodeTabs: { links: destinations } };
  const context = vm.createContext({ document, Drupal, drupalSettings: settings, jQuery: $, once, MutationObserver,
    URL: class extends URL { constructor(...args) { super(...args); queries.urls++; } },
    window: { location: { hash }, queueMicrotask: callback => queued.push(callback) },
    requestAnimationFrame: callback => queued.push(callback),
  });
  vm.runInContext(fieldGroup, context);
  vm.runInContext(validation, context);
  // Theme markup is the adapter's responsibility; tab behavior is contrib code.
  Drupal.theme.horizontalTab = () => {
    const item = element('li', { class: 'horizontal-tab-button' });
    const link = element('a', { href: '#' });
    item.append(link);
    return { item: $(item), link: $(link), summary: $(element('span')) };
  };
  vm.runInContext(script, context);

  const wrapper = () => {
    const container = element('div', { class: 'form-type-horizontal-tabs field-group-tabs-wrapper' });
    const list = element('ul', { 'data-horizontal-tabs-list': '' });
    const panes = element('div', { 'data-horizontal-tabs-panes': '' });
    const input = element('input', { class: 'horizontal-tabs-active-tab' });
    input.hidden = true;
    container.append(list, panes);
    panes.append(input);
    return { container, list, panes, input };
  };
  const makeRoot = (newIds = ids) => {
    const root = element('form', { class: 'node-form' });
    const group = wrapper();
    root.append(group.container);
    const panes = newIds.map(id => {
      const pane = element('details', { id, class: 'horizontal-tabs-pane' });
      pane.open = true;
      const input = element('input');
      pane.append(input);
      group.panes.append(pane);
      if (!mobile) {
        const tab = new Drupal.HorizontalTab({ details: $(pane), title: id });
        group.list.append(tab.item[0]);
        $(pane).data('horizontalTab', tab);
        pane.tab = tab;
      }
      pane.input = input;
      return pane;
    });
    if (!mobile) panes[0].tab.focus();
    else panes.slice(1).forEach(pane => { pane.open = false; });
    return { root, panes, group };
  };
  let active = makeRoot();
  document.html.append(active.root);
  if (errors) active.panes[0].input.classList.add('error');
  const link = href => element('a', { href });
  const links = [link(destinations[0]), link(`${destinations[1]}?destination=/search/kwe`),
    link('/node/43/edit'), link('https://elsewhere.test/node/42/edit'), link(`${destinations[1]}#specific-field`)];
  document.html.append(...links);
  for (let index = 0; index < extraLinks; index++) document.html.append(link(`/unrelated/${index}`));
  const flush = () => {
    while (queued.length || observers.some(observer => observer.records.length)) {
      while (queued.length) queued.shift()();
      for (const observer of observers) {
        if (observer.records.length) { const records = observer.records.splice(0); observer.callback(records); }
      }
    }
  };
  const attach = (fragment = document) => { Drupal.behaviors.ddbgoNodeTabs.attach(fragment); flush(); };
  const emit = (name, target, extra = {}) => {
    const event = { type: name, target, preventDefault() { this.defaultPrevented = true; }, ...extra };
    for (let node = target; node; node = name === 'toggle' || name === 'invalid' ? null : node.parentElement) {
      for (const [eventName, handlers] of node.handlers) {
        if (eventName.split('.')[0] === name) [...handlers].forEach(handler => handler(event));
      }
    }
    listeners.get(name)?.forEach(callback => callback(event));
    return event;
  };
  return { attach, emit, flush, links, listeners, queries, document, link, element,
    get panes() { return active.panes; }, get root() { return active.root; },
    nest(childIndex, parentIndex) {
      const child = active.panes[childIndex];
      const group = wrapper();
      active.panes[parentIndex].append(group.container);
      group.panes.append(child);
      if (child.tab) { group.list.append(child.tab.item[0]); child.tab.focus(); }
    },
    replaceForm(newIds = ids) {
      active.root.remove();
      active = makeRoot(newIds);
      document.html.append(active.root);
      return active.root;
    },
    validate(pane) {
      Drupal.behaviors.fieldGroupTabsValidation.attach(active.root);
      emit('invalid', pane.input);
      flush();
    },
  };
}

const section = link => new URL(link.href).hash;

test('real Field Group click/Enter changes links and retains its active-tab input', () => {
  const p = page();
  p.attach();
  const tab = p.panes[1].tab;
  assert.equal(p.emit('click', tab.link[0]).defaultPrevented, true);
  p.flush();
  assert.equal(section(p.links[0]), '#ddbgo-tab=edit-group-informationen');
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
  assert.equal(new URL(p.links[1].href).searchParams.get('destination'), '/search/kwe');
  assert.equal(p.panes[1].parentElement.children[0].value, p.panes[1].id);
  assert.equal(p.links[2].href, 'https://ddbgo.test/node/43/edit');
  assert.equal(p.links[3].href, 'https://elsewhere.test/node/42/edit');
  assert.equal(section(p.links[4]), '#specific-field');
  p.emit('keydown', p.panes[0].tab.link[0], { keyCode: 13 });
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-status');
});

test('initial fragment restores once; AJAX indexes only its new links', () => {
  const p = page({ hash: '#ddbgo-tab=edit-group-informationen', extraLinks: 1000 });
  p.attach();
  assert.equal(p.panes[1].tab.item.hasClass('selected'), true);
  p.panes[0].tab.focus();
  p.flush();
  const fragment = p.element('div');
  const fresh = p.link('/node/42/edit?destination=/other');
  fragment.append(fresh, p.link('/unrelated/ajax'));
  p.document.html.append(fragment);
  const before = { ...p.queries };
  p.attach(fragment);
  assert.equal(section(fresh), '#ddbgo-tab=edit-group-status');
  assert.equal(p.panes[0].tab.item.hasClass('selected'), true);
  assert.equal(p.queries.documentLinks, before.documentLinks);
  assert.ok(p.queries.urls - before.urls <= 4); // At most settings and the two inserted anchors.
  assert.equal(p.listeners.get('click').length, 1);
  const standalone = p.link('/node/42/edit');
  p.document.html.append(standalone);
  p.attach(standalone);
  assert.equal(section(standalone), '#ddbgo-tab=edit-group-status');
});

test('typing and unrelated interactions do no link inventory or pane scans', () => {
  const p = page({ extraLinks: 2000 });
  p.attach();
  const before = { ...p.queries };
  for (let index = 0; index < 500; index++) {
    for (const event of ['click', 'keydown', 'auxclick', 'contextmenu']) p.emit(event, p.panes[0].input);
  }
  for (let index = 0; index < 500; index++) p.emit('keydown', p.links[1], { key: 'a', keyCode: 65 });
  assert.deepEqual(p.queries, before);
  for (const event of ['click', 'keydown', 'auxclick', 'contextmenu']) p.emit(event, p.links[2], { keyCode: 13 });
  assert.ok(p.queries.urls - before.urls <= 4);
  assert.equal(p.queries.panes, before.panes);
  assert.equal(p.queries.documentLinks, before.documentLinks);
  p.panes[1].tab.focus();
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
  assert.equal(p.queries.documentLinks, before.documentLinks);
  assert.ok(p.queries.urls - before.urls <= 6); // Only two destination links beyond the four navigation events.
});

test('validation, missing tabs and explicit anchors retain Drupal selection', () => {
  for (const options of [
    { hash: '#ddbgo-tab=edit-group-informationen', errors: true },
    { hash: '#ddbgo-tab=does-not-exist' }, { hash: '#specific-field' },
  ]) {
    const p = page(options);
    p.attach();
    assert.equal(p.panes[0].tab.item.hasClass('selected'), true);
  }
  const p = page({ hash: '#ddbgo-tab=edit-group-status' });
  p.attach();
  p.validate(p.panes[1]);
  assert.equal(p.panes[1].tab.item.hasClass('selected'), true);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
  p.attach(p.root);
  assert.equal(p.panes[1].tab.item.hasClass('selected'), true);
});

test('aliases, language prefixes and AJAX suffixes preserve the same section', () => {
  for (const [id, key] of [['edit-group-s', 'edit-group-status'],
    ['edit-group-geografische-ausrichtung', 'edit-group-ausrichtung'],
    ['edit-group-europenana-archivportal', 'edit-group-europeana-archivportal'],
    ['edit-group-informationen--ajax-id', 'edit-group-informationen']]) {
    const p = page({ ids: ['edit-first', id], hash: `#ddbgo-tab=${key}`,
      destinations: ['/de/kwe/alias', '/de/node/42/edit'] });
    p.attach();
    assert.equal(p.panes[1].tab.item.hasClass('selected'), true);
    assert.equal(section(p.links[1]), `#ddbgo-tab=${key}`);
    assert.equal(new URL(p.links[0].href).pathname, '/de/kwe/alias');
  }
});

test('nested restore and programmatic tabHide use Field Group visibility', () => {
  const p = page({ ids: ['edit-first', 'edit-parent', 'edit-child'], hash: '#ddbgo-tab=edit-child' });
  p.nest(2, 1);
  p.attach();
  assert.equal(p.panes[1].tab.item.hasClass('selected'), true);
  assert.equal(p.panes[2].tab.item.hasClass('selected'), true);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-child');
  p.panes[0].tab.tabShow();
  p.flush();
  p.panes[1].tab.tabHide();
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-first');
  p.panes[1].tab.tabShow();
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-child');
});

test('mobile details follow opening and closing without tab objects', () => {
  const p = page({ mobile: true, hash: '#ddbgo-tab=edit-group-informationen' });
  p.attach();
  assert.equal(p.panes[1].open, true);
  p.emit('toggle', p.panes[0]);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-status');
  p.panes[0].open = false;
  p.emit('toggle', p.panes[0]);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
});

test('new-tab/menu/keyboard navigation updates only its anchor synchronously', () => {
  for (const event of ['click', 'keydown', 'auxclick', 'contextmenu']) {
    const p = page();
    p.attach();
    const fresh = p.link('/node/42/edit?destination=/next');
    const child = p.element('span');
    fresh.append(child);
    p.document.html.append(fresh); // No behavior attach yet.
    p.panes[1].tab.focus(); // MutationObserver has not run yet.
    const before = { ...p.queries };
    const interaction = p.emit(event, child, { keyCode: 13, button: 1, ctrlKey: true });
    assert.equal(section(fresh), '#ddbgo-tab=edit-group-informationen');
    assert.equal(new URL(fresh.href).searchParams.get('destination'), '/next');
    assert.equal(interaction.defaultPrevented, undefined);
    assert.equal(p.queries.documentLinks, before.documentLinks);
    assert.ok(p.queries.urls - before.urls <= 1);
    p.flush();
  }
});

test('AJAX form replacement drops detached tabs and observes fresh ones', () => {
  const p = page({ hash: '#ddbgo-tab=edit-group-informationen' });
  p.attach();
  const oldPane = p.panes[1];
  const replacement = p.replaceForm(['edit-group-status--ajax', 'edit-group-informationen--ajax']);
  p.attach(replacement);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-status');
  oldPane.tab.focus();
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-status');
  p.validate(p.panes[1]);
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
});
test('same-selection AJAX attach releases removed links before a later tab switch', () => {
  const p = page();
  p.attach();
  const removed = p.link('/node/42/edit');
  p.document.html.append(removed);
  p.attach(removed);
  removed.remove();
  p.attach(); // The selected key is unchanged, but the removed link must go.
  Object.defineProperty(removed, 'isConnected', {
    get() { throw new Error('Removed link retained across subsequent updates'); },
  });
  p.attach();
  p.panes[1].tab.focus();
  p.flush();
  assert.equal(section(p.links[1]), '#ddbgo-tab=edit-group-informationen');
});