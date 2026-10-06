/**
 * Run: node --test web/modules/custom/ddbgo_gin/tests/js/bookmark-feedback.test.cjs
 *
 * Dependency-free integration checks using the installed Flag AJAX command.
 * The small DOM fixture checks command timing, dismissal and focus contracts;
 * it does not replace browser keyboard or screenreader verification.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../js/ddbgo_gin.bookmark-feedback.js');
const flagPath = path.resolve(__dirname, '../../../../contrib/flag/js/flag-action_link_flash.js');

function environment({loadFlag = true, commands = true} = {}) {
  const announcements = [];
  const translations = [];
  let document;

  // Only selectors needed by Flag and this behavior are supported. Unexpected
  // DOM contracts fail loudly instead of silently making the tests pass.
  const matchesSimple = (element, selector) => {
    if (selector === '*') return true;
    const tag = selector.match(/^[a-z][a-z0-9-]*/i)?.[0];
    if (tag && element.tagName !== tag.toUpperCase()) return false;
    for (const [, name] of selector.matchAll(/\.([\w-]+)/g)) {
      if (!element.classList.contains(name)) return false;
    }
    for (const [, id] of selector.matchAll(/#([\w-]+)/g)) {
      if (element.id !== id) return false;
    }
    for (const [, name, quote, value] of selector.matchAll(/\[([\w-]+)(?:=(["']?)(.*?)\2)?\]/g)) {
      if (!element.hasAttribute(name)) return false;
      if (value !== undefined && element.getAttribute(name) !== value) return false;
    }
    const remainder = selector.replace(/^[a-z][a-z0-9-]*/i, '')
      .replace(/[.#][\w-]+/g, '').replace(/\[[^\]]+\]/g, '');
    assert.equal(remainder, '', `Unsupported fixture selector: ${selector}`);
    return true;
  };
  const matches = (element, selector) => selector.split(',').some(part => {
    const components = part.trim().split(/\s+/);
    if (!matchesSimple(element, components.pop())) return false;
    let ancestor = element.parentElement;
    while (components.length) {
      const component = components.pop();
      while (ancestor && !matchesSimple(ancestor, component)) ancestor = ancestor.parentElement;
      if (!ancestor) return false;
      ancestor = ancestor.parentElement;
    }
    return true;
  });

  class Element {
    constructor(tag) {
      this.tagName = tag.toUpperCase();
      this.children = [];
      this.parentNode = null;
      this.attributes = new Map();
      this.listeners = new Map();
      this.style = {};
      this.focusCount = 0;
      this.ownText = '';
      this.classList = {
        contains: name => this.className.split(/\s+/).includes(name),
        add: (...names) => { this.className = [...new Set([...this.className.split(/\s+/).filter(Boolean), ...names])].join(' '); },
        remove: (...names) => { this.className = this.className.split(/\s+/).filter(name => !names.includes(name)).join(' '); },
      };
    }
    get ownerDocument() { return document; }
    get parentElement() { return this.parentNode; }
    get childNodes() { return this.children; }
    get firstElementChild() { return this.children[0] || null; }
    get className() { return this.getAttribute('class') || ''; }
    set className(value) { this.setAttribute('class', value); }
    get id() { return this.getAttribute('id') || ''; }
    set id(value) { this.setAttribute('id', value); }
    get type() { return this.getAttribute('type') || ''; }
    set type(value) { this.setAttribute('type', value); }
    get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
    set textContent(value) { this.ownText = String(value); this.children.forEach(child => { child.parentNode = null; }); this.children = []; }
    get innerText() { return this.textContent; }
    set innerText(value) { this.textContent = value; }
    get isConnected() { return document.documentElement.contains(this); }
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
    hasAttribute(name) { return this.attributes.has(name); }
    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    removeAttribute(name) { this.attributes.delete(name); }
    appendChild(child) {
      if (child.parentNode) child.remove();
      child.parentNode = this;
      this.children.push(child);
      return child;
    }
    append(...children) { children.forEach(child => this.appendChild(child)); }
    remove() {
      if (this.parentNode) this.parentNode.children = this.parentNode.children.filter(child => child !== this);
      this.parentNode = null;
    }
    contains(element) { return this === element || this.children.some(child => child.contains(element)); }
    matches(selector) { return matches(this, selector); }
    closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector) || null; }
    querySelectorAll(selector) {
      const result = [];
      const visit = node => node.children.forEach(child => {
        if (child.matches(selector)) result.push(child);
        visit(child);
      });
      visit(this);
      return result;
    }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    getElementsByTagName(tag) { return this.querySelectorAll(tag); }
    addEventListener(type, callback) {
      if (!this.listeners.has(type)) this.listeners.set(type, []);
      this.listeners.get(type).push(callback);
    }
    dispatchEvent(event) {
      event.target ||= this;
      let current = this;
      do {
        event.currentTarget = current;
        for (const listener of current.listeners.get(event.type) || []) listener.call(current, event);
        current = event.bubbles && !event.propagationStopped ? current.parentElement : null;
      } while (current);
      return !event.defaultPrevented;
    }
    focus(options) { this.focusCount++; this.lastFocusOptions = options; document.activeElement = this; }
    click() { return this.dispatchEvent(event('click')); }
  }
  const event = (type, extra = {}) => ({
    type, bubbles: true, defaultPrevented: false, propagationStopped: false,
    preventDefault() { this.defaultPrevented = true; },
    stopPropagation() { this.propagationStopped = true; },
    ...extra,
  });
  const html = new Element('html');
  const body = new Element('body');
  html.append(body);
  document = {
    documentElement: html, body, activeElement: body,
    createElement: tag => new Element(tag),
    querySelectorAll: selector => html.querySelectorAll(selector),
    querySelector: selector => html.querySelector(selector),
  };
  const Drupal = {
    behaviors: {},
    t: text => { translations.push(text); return text; },
    announce: (message, priority) => announcements.push({message, priority}),
  };
  if (commands) Drupal.AjaxCommands = function AjaxCommands() {};
  const context = vm.createContext({Drupal, document, Element});
  const installFlag = () => {
    if (!Drupal.AjaxCommands) Drupal.AjaxCommands = function AjaxCommands() {};
    vm.runInContext(fs.readFileSync(flagPath, 'utf8'), context, {filename: flagPath});
  };
  if (loadFlag) installFlag();
  vm.runInContext(fs.readFileSync(sourcePath, 'utf8'), context, {filename: sourcePath});
  const attach = (root = document) => Drupal.behaviors.ddbgoBookmarkFeedback.attach(root);
  const wrapper = (id, bookmark = true, link = true) => {
    const element = new Element('div');
    element.id = id;
    element.className = bookmark ? 'flag flag-bookmark' : 'flag flag-other';
    if (link) {
      const anchor = new Element('a');
      anchor.setAttribute('href', '/flag/' + id);
      anchor.textContent = 'Lesezeichen setzen';
      element.append(anchor);
    }
    body.append(element);
    return element;
  };
  const command = (element, message = 'Lesezeichen gespeichert.', status = 'success') => {
    const instance = new Drupal.AjaxCommands();
    instance.actionLinkFlash({}, {selector: '#' + element.id, message}, status);
    return element.querySelectorAll('.js-flag-message').at(-1) || null;
  };
  const existingMessage = (element, message = 'Lesezeichen gespeichert.') => {
    const paragraph = new Element('p');
    paragraph.className = 'js-flag-message';
    paragraph.textContent = message;
    paragraph.setAttribute('aria-live', 'polite');
    element.append(paragraph);
    return paragraph;
  };
  return {document, Drupal, Element, announcements, translations, attach, wrapper, command, existingMessage, event, installFlag};
}

test('the real Flag command appends after attach, announces once and gets a named dismiss button', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  assert.equal(bookmark.querySelector('.js-flag-message'), null);
  const message = env.command(bookmark);
  const button = message.querySelector('button.ddbgo-bookmark-message-dismiss');
  assert.ok(button, 'Decorate the message created by the command after behavior attachment');
  assert.equal(button.type, 'button', 'Dismissal must never submit a surrounding form');
  assert.equal(button.getAttribute('aria-label'), 'Lesezeichenmeldung schließen');
  const icon = button.querySelector('span');
  assert.equal(icon.getAttribute('aria-hidden'), 'true');
  assert.equal(icon.textContent, '×');
  assert.ok(env.translations.includes('Lesezeichenmeldung schließen'));
  assert.equal(message.getAttribute('aria-live'), null, 'Keep Flag\'s explicit announcement without a second live-region update');
  assert.deepEqual(env.announcements, [{message: 'Lesezeichen gespeichert.', priority: 'assertive'}]);
});

test('initial messages and replacement contexts are enhanced without announcing or moving focus', () => {
  const env = environment();
  const first = env.wrapper('first');
  const paragraph = env.existingMessage(first);
  const before = new env.Element('input');
  env.document.body.append(before);
  before.focus();
  env.attach();
  assert.equal(paragraph.querySelectorAll('button').length, 1);
  assert.equal(paragraph.getAttribute('aria-live'), null);
  const replacement = env.wrapper('replacement');
  const newMessage = env.existingMessage(replacement);
  env.attach(replacement);
  assert.equal(newMessage.querySelectorAll('button').length, 1);
  assert.equal(env.document.activeElement, before);
  assert.deepEqual(env.announcements, []);
});

test('repeated attachments preserve the command wrapper, one button and one click handler', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  const paragraph = env.existingMessage(bookmark);
  env.attach();
  const command = env.Drupal.AjaxCommands.prototype.actionLinkFlash;
  const button = paragraph.querySelector('button');
  env.attach(); env.attach(bookmark); env.attach(paragraph);
  assert.equal(env.Drupal.AjaxCommands.prototype.actionLinkFlash, command);
  assert.equal(paragraph.querySelectorAll('button').length, 1);
  assert.equal(paragraph.querySelector('button'), button);
  button.focus(); button.click();
  assert.equal(bookmark.querySelector('a').focusCount, 1, 'No duplicate dismissal listener');
  assert.equal(paragraph.isConnected, false);
});

test('the decorated command preserves the original receiver, all arguments and return value', () => {
  const env = environment();
  const calls = [];
  const result = {original: true};
  const real = env.Drupal.AjaxCommands.prototype.actionLinkFlash;
  env.Drupal.AjaxCommands.prototype.actionLinkFlash = function (...args) {
    calls.push({receiver: this, args});
    real.apply(this, args);
    return result;
  };
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const receiver = {identity: 'original receiver'};
  const args = [{original: 'ajax'}, {selector: '#bookmark', message: 'Entfernt.'}, 'success', 'extra'];
  const returned = env.Drupal.AjaxCommands.prototype.actionLinkFlash.apply(receiver, args);
  assert.equal(returned, result);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].receiver, receiver);
  assert.deepEqual(calls[0].args, args);
  assert.equal(bookmark.querySelectorAll('button').length, 1);
});

test('an exception in the original command remains visible to Drupal', () => {
  const env = environment();
  const error = new Error('Flag command failure');
  env.Drupal.AjaxCommands.prototype.actionLinkFlash = () => { throw error; };
  env.attach();
  assert.throws(() => env.Drupal.AjaxCommands.prototype.actionLinkFlash({}, {}, 'success'), error);
});

test('other flags keep the real command, its live region and its announcement', () => {
  const env = environment();
  const other = env.wrapper('other', false);
  const existing = env.existingMessage(other);
  env.attach();
  const added = env.command(other, 'Anderes Flag geändert.');
  assert.equal(existing.querySelector('button'), null);
  assert.equal(added.querySelector('button'), null);
  assert.equal(existing.getAttribute('aria-live'), 'polite');
  assert.equal(added.getAttribute('aria-live'), 'polite');
  assert.deepEqual(env.announcements, [{message: 'Anderes Flag geändert.', priority: 'assertive'}]);
});

test('unsuccessful commands retain Flag cleanup and do not decorate an existing message', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  bookmark.classList.add('flag-waiting');
  env.attach();
  const existing = env.existingMessage(bookmark);
  env.command(bookmark, 'Fehler.', 'error');
  assert.equal(bookmark.classList.contains('flag-waiting'), false);
  assert.equal(existing.querySelector('button'), null);
  assert.equal(existing.getAttribute('aria-live'), 'polite');
  assert.deepEqual(env.announcements, []);
});

test('empty command messages and empty existing paragraphs get no dismiss control', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  const empty = env.existingMessage(bookmark, '');
  const whitespace = env.existingMessage(bookmark, '  \n ');
  env.attach();
  env.command(bookmark, '');
  assert.equal(bookmark.querySelectorAll('button').length, 0);
  assert.equal(bookmark.querySelectorAll('.js-flag-message').length, 2);
  assert.equal(empty.getAttribute('aria-live'), 'polite');
  assert.equal(whitespace.getAttribute('aria-live'), 'polite');
  assert.deepEqual(env.announcements, []);
});

test('an empty successful command leaves an existing undecorated message alone', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const existing = env.existingMessage(bookmark);
  env.command(bookmark, '');
  assert.equal(existing.querySelector('button'), null);
  assert.equal(existing.getAttribute('aria-live'), 'polite');
  assert.deepEqual(env.announcements, []);
});

test('click dismissal removes only its own message and returns focus to the bookmark link', () => {
  const env = environment();
  const first = env.wrapper('first');
  const second = env.wrapper('second');
  env.attach();
  const own = env.command(first);
  const other = env.command(second, 'Anderes Lesezeichen gespeichert.');
  const button = own.querySelector('button');
  button.focus();
  button.click();
  assert.equal(first.isConnected, true);
  assert.equal(first.querySelector('.js-flag-message'), null);
  assert.equal(other.isConnected, true);
  assert.equal(env.document.activeElement, first.querySelector('a'));
  assert.equal(first.querySelector('a').lastFocusOptions.preventScroll, true);
  assert.equal(env.announcements.length, 2, 'Dismissal must not announce a new bookmark action');
});

test('pointer dismissal does not steal focus from another control', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const message = env.command(bookmark);
  const input = new env.Element('input');
  env.document.body.append(input); input.focus();
  message.querySelector('button').click();
  assert.equal(message.isConnected, false);
  assert.equal(env.document.activeElement, input);
  assert.equal(bookmark.querySelector('a').focusCount, 0);
});

test('focus inside a message, including its icon, is restored on dismissal', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const message = env.command(bookmark);
  const button = message.querySelector('button');
  const icon = button.querySelector('span');
  icon.focus(); button.click();
  assert.equal(env.document.activeElement, bookmark.querySelector('a'));
  assert.equal(bookmark.querySelector('a').focusCount, 1);
});

test('a message without a surviving bookmark link closes without removing the wrapper', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark', true, false);
  env.attach();
  const message = env.command(bookmark);
  const button = message.querySelector('button');
  button.focus();
  assert.doesNotThrow(() => button.click());
  assert.equal(message.isConnected, false);
  assert.equal(bookmark.isConnected, true, 'Do not run Flag\'s old animation cleanup when dismissing');
  assert.equal(env.document.activeElement, bookmark, 'Keep a programmatic focus position when the link is absent');
  assert.equal(bookmark.getAttribute('tabindex'), '-1', 'The fallback must not add a new Tab stop');
  assert.equal(bookmark.lastFocusOptions.preventScroll, true);
});

test('Escape closes its own message and consumes the event', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const message = env.command(bookmark);
  const button = message.querySelector('button');
  button.focus();
  const escape = env.event('keydown', {key: 'Escape'});
  button.dispatchEvent(escape);
  assert.equal(message.isConnected, false);
  assert.equal(escape.defaultPrevented, true);
  assert.equal(escape.propagationStopped, true);
  assert.equal(env.document.activeElement, bookmark.querySelector('a'));
});

test('already handled Escape and unrelated keys leave the message intact', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const message = env.command(bookmark);
  const button = message.querySelector('button');
  button.focus();
  const handled = env.event('keydown', {key: 'Escape', defaultPrevented: true});
  button.dispatchEvent(handled);
  assert.equal(message.isConnected, true);
  assert.equal(handled.propagationStopped, false);
  for (const key of ['Tab', 'Enter', ' ', 'ArrowLeft']) {
    const event = env.event('keydown', {key});
    button.dispatchEvent(event);
    assert.equal(message.isConnected, true, key);
    assert.equal(event.defaultPrevented, false, key);
  }
  assert.equal(env.document.activeElement, button);
});

test('Escape and pointer events elsewhere do not dismiss a message', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const message = env.command(bookmark);
  const elsewhere = new env.Element('input');
  env.document.body.append(elsewhere); elsewhere.focus();
  const escape = env.event('keydown', {key: 'Escape'});
  elsewhere.dispatchEvent(escape);
  elsewhere.click();
  assert.equal(message.isConnected, true);
  assert.equal(escape.defaultPrevented, false);
  assert.equal(env.document.activeElement, elsewhere);
});

test('AJAX replacement gets a new dismiss button and uses the new bookmark link', () => {
  const env = environment();
  const oldWrapper = env.wrapper('bookmark');
  env.attach();
  const oldMessage = env.command(oldWrapper);
  const oldButton = oldMessage.querySelector('button');
  oldWrapper.remove();
  const replacement = env.wrapper('bookmark');
  env.attach(replacement);
  const message = env.command(replacement, 'Lesezeichen entfernt.');
  const button = message.querySelector('button');
  assert.notEqual(button, oldButton);
  assert.equal(message.querySelectorAll('button').length, 1);
  button.focus(); button.click();
  assert.equal(env.document.activeElement, replacement.querySelector('a'));
  assert.equal(oldWrapper.querySelector('a').focusCount, 0);
});

test('absent AJAX commands and a Flag script loaded later are safe', () => {
  for (const commands of [false, true]) {
    const env = environment({loadFlag: false, commands});
    const bookmark = env.wrapper('bookmark');
    const existing = env.existingMessage(bookmark);
    assert.doesNotThrow(() => env.attach());
    assert.equal(existing.querySelectorAll('button').length, 1);
    env.installFlag();
    assert.doesNotThrow(() => env.attach());
    const added = env.command(bookmark, 'Später geladenes Flag.');
    assert.equal(added.querySelectorAll('button').length, 1);
    assert.deepEqual(env.announcements, [{message: 'Später geladenes Flag.', priority: 'assertive'}]);
  }
});

test('message text remains literal and the close icon is the only added visible content', () => {
  const env = environment();
  const bookmark = env.wrapper('bookmark');
  env.attach();
  const text = 'Gespeichert: <script>alert(1)</script> & „Objekt“';
  const message = env.command(bookmark, text);
  assert.equal(message.ownText, text);
  assert.equal(message.querySelectorAll('script').length, 0);
  assert.equal(message.textContent, text + '×');
  assert.deepEqual(env.announcements, [{message: text, priority: 'assertive'}]);
});
