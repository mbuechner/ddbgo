/**
 * Run: node --test web/modules/custom/ddbgo_gin/tests/js/focus-helpers.test.cjs
 *
 * Exercises installed Gin and Drupal once with a small DOM surface. This checks
 * their event/focus contracts, not browser rendering or native keyboard input.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const web = path.resolve(__dirname, '../../../../..');
const ginPath = path.join(web, 'themes/contrib/gin/dist/js/more_actions.js');
const oncePath = path.join(web, 'core/assets/vendor/once/once.min.js');
const helperPath = path.resolve(__dirname, '../../js/ddbgo_gin.focus-helpers.js');

class Event {
  constructor(type, options = {}) {
    this.type = type;
    this.bubbles = options.bubbles || false;
    this.defaultPrevented = false;
  }
  preventDefault() { this.defaultPrevented = true; }
}

// Only selectors, attributes, tree traversal and events used by installed Gin
// and once. Production behavior functions and once bookkeeping are not mocked.
class Element {
  constructor(tagName, attributes = {}) {
    this.nodeType = 1;
    this.tagName = tagName.toUpperCase();
    this.attributes = new Map(Object.entries(attributes));
    this.children = [];
    this.parentNode = null;
    this.listeners = new Map();
    this.style = {};
    this.textContent = '';
    this.classList = {
      contains: name => (this.className || '').split(/\s+/).includes(name),
      add: name => { if (!this.classList.contains(name)) this.className = `${this.className} ${name}`.trim(); },
      remove: name => { this.className = this.className.split(/\s+/).filter(value => value !== name).join(' '); },
    };
  }
  get id() { return this.getAttribute('id') || ''; }
  get className() { return this.getAttribute('class') || ''; }
  set className(value) { this.setAttribute('class', value); }
  get href() { return this.getAttribute('href'); }
  set href(value) { this.setAttribute('href', value); }
  get dataset() { return {drupalSelector: this.getAttribute('data-drupal-selector')}; }
  get nextElementSibling() {
    if (!this.parentNode) return null;
    return this.parentNode.children[this.parentNode.children.indexOf(this) + 1] || null;
  }
  get ownerDocument() {
    let element = this;
    while (element.parentNode) element = element.parentNode;
    return element.nodeType === 9 ? element : this.document;
  }
  getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  hasAttribute(name) { return this.attributes.has(name); }
  removeAttribute(name) { this.attributes.delete(name); }
  appendChild(element) {
    element.remove();
    element.parentNode = this;
    element.document = this.ownerDocument;
    this.children.push(element);
    return element;
  }
  append(...elements) { elements.forEach(element => this.appendChild(element)); }
  remove() {
    if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(element => element !== this);
    this.parentNode = null;
  }
  matches(selector) {
    return String(selector).split(',').some(part => {
      const components = part.trim().split(/\s+(?![^\[]*\])/);
      let element = this;
      if (!element.matchesSimple(components.pop())) return false;
      while (components.length) {
        const ancestorSelector = components.pop();
        do { element = element.parentNode; } while (element && !element.matchesSimple(ancestorSelector));
        if (!element) return false;
      }
      return true;
    });
  }
  matchesSimple(selector) {
    let excluded = false;
    const expression = selector.replace(/:not\(([^)]+)\)/g, (_, condition) => {
      excluded ||= this.matches(condition);
      return '';
    });
    if (excluded) return false;
    const tag = expression.match(/^[a-z][\w-]*/i);
    if (tag && this.tagName !== tag[0].toUpperCase()) return false;
    for (const match of expression.matchAll(/#([\w-]+)/g)) if (this.id !== match[1]) return false;
    for (const match of expression.matchAll(/\.([\w-]+)/g)) if (!this.classList.contains(match[1])) return false;
    for (const match of expression.matchAll(/\[([\w-]+)(?:(~=|=)["']?([^\]"']*)["']?)?\]/g)) {
      const [, name, operator, expected] = match;
      const value = this.getAttribute(name);
      if (value === null || (operator === '=' && value !== expected)) return false;
      if (operator === '~=' && !value.split(/\s+/).includes(expected)) return false;
    }
    return true;
  }
  closest(selector) {
    for (let element = this; element; element = element.parentNode) if (element.matches(selector)) return element;
    return null;
  }
  querySelectorAll(selector) {
    const result = [];
    for (const child of this.children) {
      if (child.matches(selector)) result.push(child);
      result.push(...child.querySelectorAll(selector));
    }
    return result;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  addEventListener(type, listener) {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type).add(listener);
  }
  removeEventListener(type, listener) { this.listeners.get(type)?.delete(listener); }
  dispatchEvent(event) {
    event.target ||= this;
    event.currentTarget = this;
    for (const listener of [...(this.listeners.get(event.type) || [])]) listener.call(this, event);
    if (event.bubbles && this.parentNode) this.parentNode.dispatchEvent(event);
    return !event.defaultPrevented;
  }
  focus() {
    this.ownerDocument.activeElement = this;
    this.dispatchEvent(new Event('focus'));
  }
  click() { this.dispatchEvent(new Event('click', {bubbles: true})); }
}

function environment() {
  const document = new Element('document');
  document.nodeType = 9;
  document.createElement = tag => { const element = new Element(tag); element.document = document; return element; };
  const Drupal = {behaviors: {}, t: text => `translated: ${text}`};
  const context = vm.createContext({document, Drupal, Element, Event, console});
  const load = filename => vm.runInContext(fs.readFileSync(filename, 'utf8'), context, {filename});
  load(oncePath);
  load(ginPath);
  const original = {...Drupal.ginStickyFormActions};
  load(helperPath);

  const region = new Element('div', {class: 'region-content'});
  const sticky = new Element('div', {class: 'gin-sticky-form-actions'});
  const stickyButton = new Element('button', {'data-drupal-selector': 'gin-sticky-edit-submit'});
  sticky.append(stickyButton);
  const menu = new Element('div', {class: 'gin-more-actions'});
  const trigger = new Element('button', {class: 'gin-more-actions__trigger', 'aria-expanded': 'false'});
  menu.append(trigger);
  const outside = new Element('button', {id: 'outside'});
  document.append(region, sticky, menu, outside);
  const form = (id = 'main-form', parentFallback = false) => {
    const element = new Element('form', {id, class: 'gin--has-sticky-form-actions'});
    const actions = new Element('div', {'data-drupal-selector': 'edit-actions'});
    const button = new Element('button', {'data-drupal-selector': 'edit-submit'});
    actions.append(button);
    const helper = new Element('a', {'data-gin-move-focus-to-sticky-bar': '', href: '#', class: 'visually-hidden'});
    const after = new Element('input', {id: `${id}-after`});
    if (parentFallback) {
      const wrapper = new Element('div');
      wrapper.append(helper);
      element.append(actions, wrapper, after);
    } else element.append(actions, helper, after);
    region.append(element);
    return {element, actions, button, helper, after};
  };
  const attach = (attachment = document) => Drupal.behaviors.ginFormActions.attach(attachment);
  return {document, Drupal, original, sticky, stickyButton, region, menu, trigger, outside, form, attach, once: context.once};
}

test('valid focus markers transfer focus and remove the translated return helper', () => {
  for (const parentFallback of [false, true]) {
    const env = environment();
    const fixture = env.form('main-form', parentFallback);
    env.outside.focus();
    env.attach();
    assert.equal(env.document.activeElement, env.outside, 'attachment does not steal focus');
    fixture.helper.focus();
    assert.equal(env.document.activeElement, env.stickyButton);
    const returnLink = env.sticky.querySelector('[data-gin-move-focus-to-end-of-form]');
    assert.ok(returnLink);
    assert.equal(returnLink.textContent, 'translated: Moves focus back to form');
    assert.equal(returnLink.getAttribute('role'), 'button');
    assert.equal(returnLink.parentNode.style.display, 'contents');
    assert.equal(env.document.querySelector('[gin-move-focus-to-end-of-form]'), null);
    returnLink.focus();
    assert.equal(env.document.activeElement, fixture.after, 'return follows sibling or parent-sibling path');
    assert.equal(env.sticky.querySelector('[data-gin-move-focus-to-end-of-form]'), null);
    assert.equal(env.sticky.children.length, 1, 'temporary wrapper is removed');
  }
});

test('real Drupal once prevents repeated attach handlers and supports AJAX replacement', () => {
  const env = environment();
  const fixture = env.form();
  env.attach();
  env.attach();
  env.attach(fixture.element);
  env.Drupal.ginStickyFormActions.moveFocus(env.sticky, fixture.element);
  env.Drupal.ginStickyFormActions.moveFocus(env.sticky, fixture.element);
  assert.equal(fixture.helper.listeners.get('focus').size, 1);
  fixture.helper.focus();
  assert.equal(env.sticky.querySelectorAll('[data-gin-move-focus-to-end-of-form]').length, 1);
  env.sticky.querySelector('[data-gin-move-focus-to-end-of-form]').focus();

  fixture.element.remove();
  const replacement = env.form();
  env.outside.focus();
  env.attach(env.region);
  env.attach(env.region);
  assert.equal(env.document.activeElement, env.outside);
  assert.equal(replacement.helper.listeners.get('focus').size, 1);
  replacement.helper.focus();
  assert.equal(env.document.activeElement, env.stickyButton);
  env.sticky.querySelector('[data-gin-move-focus-to-end-of-form]').focus();
  assert.equal(env.document.activeElement, replacement.after);
});

test('only moveFocus is replaced; Gin action dispatch, AJAX and menus still operate', () => {
  const env = environment();
  const fixture = env.form();
  for (const [name, implementation] of Object.entries(env.original)) {
    if (name === 'moveFocus') assert.notEqual(env.Drupal.ginStickyFormActions[name], implementation);
    else assert.equal(env.Drupal.ginStickyFormActions[name], implementation, `${name} remains installed Gin code`);
  }
  env.once('drupal-ajax', fixture.button);
  const actionEvents = [];
  fixture.button.addEventListener('mousedown', () => actionEvents.push('mousedown'));
  fixture.button.addEventListener('click', () => actionEvents.push('click'));
  env.attach();
  env.attach();
  assert.equal(env.stickyButton.getAttribute('form'), 'main-form');
  env.stickyButton.click();
  assert.deepEqual(actionEvents, ['mousedown', 'click']);
  env.trigger.click();
  assert.equal(env.trigger.getAttribute('aria-expanded'), 'true');
  assert.ok(env.trigger.classList.contains('is-active'));
  env.outside.click();
  assert.equal(env.trigger.getAttribute('aria-expanded'), 'false');
  assert.equal(env.trigger.classList.contains('is-active'), false);
});

test('missing sticky action or following field does not create a focus failure', () => {
  const env = environment();
  const fixture = env.form();
  env.attach();
  env.stickyButton.remove();
  fixture.helper.focus();
  assert.equal(env.document.activeElement, fixture.helper);
  assert.equal(env.sticky.children.length, 0);
  env.sticky.append(env.stickyButton);
  fixture.after.remove();
  fixture.helper.focus();
  const returnLink = env.sticky.querySelector('[data-gin-move-focus-to-end-of-form]');
  assert.ok(returnLink);
  assert.doesNotThrow(() => returnLink.focus());
  assert.equal(env.sticky.children.length, 1);
});
