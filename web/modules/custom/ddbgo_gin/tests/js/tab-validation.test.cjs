'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const web = path.resolve(__dirname, '../../../../..');
const onceScript = fs.readFileSync(path.join(web, 'core/assets/vendor/once/once.min.js'), 'utf8');
const installedScript = fs.readFileSync(path.join(web, 'modules/contrib/field_group/js/field_group.tab_validation.js'), 'utf8');
const replacementScript = fs.readFileSync(path.join(__dirname, '../../js/ddbgo_gin.tab-validation.js'), 'utf8');

class Element {
  constructor(tagName, attributes = {}) {
    this.nodeType = 1;
    this.tagName = tagName.toUpperCase();
    this.attributes = new Map(Object.entries(attributes));
    this.children = [];
    this.parentElement = null;
    this.listeners = new Map();
    this.textContent = '';
    this.data = {};
  }

  getAttribute(name) { return this.attributes.get(name) ?? null; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  hasAttribute(name) { return this.attributes.has(name); }
  removeAttribute(name) { this.attributes.delete(name); }
  get open() { return this.hasAttribute('open'); }
  set open(value) {
    if (value) this.setAttribute('open', '');
    else this.removeAttribute('open');
  }

  append(...elements) {
    elements.forEach((element) => {
      this.children.push(element);
      element.parentElement = this;
    });
  }

  matches(selector) {
    const onceSelector = selector.match(/^(:not\()?\[data-once~="([^"]+)"\]\)?$/);
    if (onceSelector) {
      const contains = (this.getAttribute('data-once') || '').split(/\s+/).includes(onceSelector[2]);
      return onceSelector[1] ? !contains : contains;
    }
    if (selector === 'details:not([open])') return this.tagName === 'DETAILS' && !this.open;
    if (selector === 'details ') return this.tagName === 'DETAILS';
    if (selector === 'summary[aria-expanded=false]:not(.horizontal-tabs-pane > summary, .vertical-tabs__pane > summary)') {
      return this.tagName === 'SUMMARY' && this.getAttribute('aria-expanded') === 'false'
        && !this.parentElement.matches('.horizontal-tabs-pane, .vertical-tabs__pane');
    }
    if (selector === '.field-group-tab :input') {
      if (!['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(this.tagName)) return false;
      for (let parent = this.parentElement; parent; parent = parent.parentElement) {
        if (parent.matches('.field-group-tab')) return true;
      }
      return false;
    }
    return selector.split(',').some((part) => {
      const trimmed = part.trim();
      if (trimmed.startsWith('.')) {
        return (this.getAttribute('class') || '').split(/\s+/).includes(trimmed.slice(1));
      }
      return this.tagName === trimmed.toUpperCase();
    });
  }

  querySelectorAll(selector) {
    return this.children.flatMap((element) => [
      ...(element.matches(selector) ? [element] : []),
      ...element.querySelectorAll(selector),
    ]);
  }

  invalid() {
    Array.from(this.listeners.get('invalid.field_group') || []).forEach((listener) => {
      listener({ type: 'invalid', target: this });
    });
  }
}

function environment(script = replacementScript) {
  const document = new Element('document');
  document.nodeType = 9;
  class Collection extends Array {
    parents(selector) {
      const parents = new Set();
      this.forEach((element) => {
        for (let parent = element.parentElement; parent; parent = parent.parentElement) {
          if (parent.matches(selector)) parents.add(parent);
        }
      });
      return Collection.from(parents);
    }
    children(selector) {
      return Collection.from(this.flatMap((element) => element.children.filter((child) => child.matches(selector))));
    }
    parent() { return Collection.from(this.map((element) => element.parentElement)); }
    attr(name, value) {
      this.forEach((element) => element.setAttribute(name, value));
      return this;
    }
    not(selector) { return Collection.from(this.filter((element) => !element.matches(selector))); }
    each(callback) {
      this.forEach((element, index) => callback.call(element, index, element));
      return this;
    }
    on(eventName, listener) {
      this.forEach((element) => {
        if (!element.listeners.has(eventName)) element.listeners.set(eventName, new Set());
        element.listeners.get(eventName).add(listener);
      });
      return this;
    }
  }
  const $ = (value, context = document) => {
    if (typeof value === 'string') return Collection.from(context.querySelectorAll(value));
    if (value instanceof Element) return Collection.of(value);
    return Collection.from(value);
  };
  const Drupal = { behaviors: {} };
  const sandbox = { document, Drupal, jQuery: $, Element };
  vm.createContext(sandbox);
  vm.runInContext(onceScript, sandbox);
  vm.runInContext(script, sandbox);
  return { document, Drupal, attach: (context = document) => Drupal.behaviors.fieldGroupTabValidation.attach(context) };
}

function fixture(env, attributes = {}) {
  const details = new Element('details', { class: 'field-group-tab claro-details', ...attributes });
  const summary = new Element('summary', { class: 'claro-details__summary form-required' });
  summary.textContent = 'Contact';
  const input = new Element('input', { type: 'email', value: 'invalid-address' });
  details.append(summary, input);
  env.document.append(details);
  return { details, summary, input };
}

test('an optional invalid email opens a native mobile tab without summary ARIA', () => {
  const before = environment(installedScript);
  const original = fixture(before);
  before.attach();
  original.input.invalid();
  assert.equal(original.details.open, false, 'installed ARIA selector misses native summary state');

  const after = environment();
  const fixed = fixture(after);
  after.attach();
  assert.equal(fixed.input.hasAttribute('required'), false, 'Core required-only handler cannot cover this control');
  fixed.input.invalid();
  assert.equal(fixed.details.open, true, 'native details becomes open before browser validation focuses the control');
  assert.equal(fixed.summary.hasAttribute('aria-expanded'), false);
  assert.equal(fixed.summary.hasAttribute('role'), false);
  assert.equal(fixed.summary.textContent, 'Contact');
  assert.equal(fixed.summary.getAttribute('class'), 'claro-details__summary form-required');
});

test('nested closed details open while enhanced horizontal and vertical panes remain excluded', () => {
  for (const paneClass of ['horizontal-tabs-pane', 'vertical-tabs__pane']) {
    const env = environment();
    const pane = fixture(env, { class: `field-group-tab ${paneClass}` });
    const tabData = { tabShow() {} };
    pane.details.data = { tab: tabData };
    const inner = new Element('details', { class: 'claro-details' });
    const innerSummary = new Element('summary');
    const middle = new Element('details');
    const control = new Element('select');
    middle.append(new Element('summary'), control);
    inner.append(innerSummary, middle);
    pane.details.append(inner);
    env.attach();
    control.invalid();

    assert.equal(inner.open, true);
    assert.equal(middle.open, true);
    assert.equal(pane.details.open, false, 'FieldGroup tabs_validation retains pane control');
    assert.equal(pane.details.data.tab, tabData, 'existing tab APIs and data remain intact');
  }
});

test('the installed once token prevents duplicate bindings across AJAX attaches', () => {
  const env = environment();
  const initial = fixture(env);
  env.attach();
  env.attach();
  env.attach(initial.details);
  assert.equal(initial.input.listeners.get('invalid.field_group').size, 1);
  assert.equal(initial.input.getAttribute('data-once'), 'field-group-tab-validation');

  const replacement = fixture(env);
  env.attach(replacement.details);
  assert.equal(replacement.input.listeners.get('invalid.field_group').size, 1);
  replacement.input.invalid();
  assert.equal(replacement.details.open, true);
});

test('already open details remain open and controls outside FieldGroup tabs are untouched', () => {
  const env = environment();
  const visible = fixture(env, { open: '' });
  const outside = fixture(env, { class: 'claro-details' });
  env.attach();
  visible.input.invalid();
  outside.input.invalid();
  assert.equal(visible.details.open, true);
  assert.equal(outside.details.open, false);
  assert.equal(outside.input.listeners.size, 0);
});
