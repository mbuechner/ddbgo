/**
 * Run: node --test web/modules/custom/ddbgo_gin/tests/js/form-status.test.cjs
 *
 * This dependency-free harness exercises callback/timer/DOM contracts. It does
 * not simulate browser rendering or prove what a screenreader announces.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../js/ddbgo_gin.form-status.js');

// Only the DOM surface used by the behavior: real attributes and tree traversal,
// without replacing any production callback, scheduling, or announcement logic.
class Element {
  constructor(tagName, attributes = {}, text = '') {
    this.nodeType = 1;
    this.tagName = tagName.toUpperCase();
    this.attributes = new Map(Object.entries(attributes));
    this.children = [];
    this.parentElement = null;
    this.ownText = text;
    this.listeners = new Map();
    this.classList = {
      contains: name => (this.getAttribute('class') || '').split(/\s+/).includes(name),
      add: name => this.setAttribute('class', `${this.getAttribute('class') || ''} ${name}`.trim()),
      remove: name => {
        const names = (this.getAttribute('class') || '').split(/\s+/).filter(item => item !== name);
        this.setAttribute('class', names.join(' '));
      },
    };
  }
  get id() { return this.getAttribute('id') || ''; }
  set id(value) { this.setAttribute('id', value); }
  get parentNode() { return this.parentElement; }
  get form() { return this.closest('form'); }
  get value() { return this.getAttribute('value') || ''; }
  set value(value) { this.setAttribute('value', value); }
  get ownerDocument() {
    let root = this;
    while (root.parentElement) root = root.parentElement;
    return root.nodeType === 9 ? root : this.document;
  }
  get isConnected() { return Boolean(this.ownerDocument?.contains(this)); }
  get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
  set textContent(value) { this.ownText = String(value); this.children = []; }
  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
  hasAttribute(name) { return this.attributes.has(name); }
  removeAttribute(name) { this.attributes.delete(name); }
  append(...nodes) {
    for (const node of nodes) {
      node.remove();
      node.parentElement = this;
      node.document = this.ownerDocument;
      this.children.push(node);
    }
  }
  remove() {
    if (this.parentElement) {
      this.parentElement.children = this.parentElement.children.filter(child => child !== this);
      this.parentElement = null;
    }
  }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  matches(selector) {
    return selector.split(',').some(part => {
      let expression = part.trim();
      if (!expression) return false;
      if (expression.startsWith(':scope > ')) expression = expression.slice(9);
      let excluded = false;
      expression = expression.replace(/:not\(([^)]+)\)/g, (_, exclusion) => {
        excluded ||= this.matches(exclusion);
        return '';
      });
      if (excluded) return false;
      const tag = expression.match(/^[a-z][a-z\d-]*/i);
      if (tag && this.tagName !== tag[0].toUpperCase()) return false;
      for (const match of expression.matchAll(/\.([\w-]+)/g)) {
        if (!this.classList.contains(match[1])) return false;
      }
      for (const match of expression.matchAll(/#([\w-]+)/g)) {
        if (this.id !== match[1]) return false;
      }
      for (const match of expression.matchAll(/\[([\w-]+)(?:(\^?=)["']?([^\]"']*)["']?)?\]/g)) {
        const [, name, operator, expected] = match;
        const value = this.getAttribute(name);
        if (value === null) return false;
        if (operator === '=' && value !== expected) return false;
        if (operator === '^=' && !value.startsWith(expected)) return false;
      }
      return true;
    });
  }
  closest(selector) {
    for (let node = this; node; node = node.parentElement) if (node.matches(selector)) return node;
    return null;
  }
  querySelectorAll(selector) {
    const result = [];
    const direct = selector.includes(':scope >');
    for (const child of this.children) {
      if (child.matches(selector)) result.push(child);
      if (!direct) result.push(...child.querySelectorAll(selector));
    }
    return result;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  addEventListener(type, handler) {
    if (!this.listeners.has(type)) this.listeners.set(type, []);
    this.listeners.get(type).push(handler);
  }
  dispatchEvent(event) {
    event.target ||= this;
    for (const handler of this.listeners.get(event.type) || []) handler(event);
  }
}

function environment() {
  const document = new Element('document');
  document.nodeType = 9;
  document.document = document;
  document.getElementById = id => document.querySelectorAll('[id]').find(element => element.id === id) || null;
  document.createElement = name => { const element = new Element(name); element.document = document; return element; };
  const form = new Element('form', {'data-ddbgo-form-status': ''});
  document.append(form);
  const announcements = [];
  const escape = value => String(value).replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
  const Drupal = {
    behaviors: {},
    ajax: {instances: []},
    announce: (message, priority) => announcements.push({message, priority}),
    t: (message, replacements = {}) => Object.entries(replacements).reduce((text, [token, replacement]) => text.replaceAll(token, token.startsWith('@') ? escape(replacement) : replacement), message),
  };
  let time = 0;
  let timerSerial = 0;
  const timers = new Map();
  const setTimeout = (callback, duration = 0) => { const id = ++timerSerial; timers.set(id, {at: time + duration, callback}); return id; };
  const clearTimeout = id => timers.delete(id);
  const advance = duration => {
    const end = time + duration;
    for (;;) {
      const ready = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
      if (!ready) break;
      const [id, timer] = ready;
      timers.delete(id); time = timer.at; timer.callback();
    }
    time = end;
  };
  const context = {
    document, Drupal, drupalSettings: {}, setTimeout, clearTimeout,
    Promise, WeakMap, WeakSet, Map, Set, Array, Error, console,
    queueMicrotask, CSS: {escape: value => value},
  };
  context.window = context;
  const onceKeys = new Set();
  context.once = key => onceKeys.has(key) ? [] : (onceKeys.add(key), [document]);
  const load = filename => vm.runInNewContext(fs.readFileSync(filename, 'utf8'), context, {filename});
  load(sourcePath);
  const attach = (attachment = document) => Object.values(Drupal.behaviors).forEach(behavior => behavior.attach(attachment, context.drupalSettings));
  const field = (id = 'name', label = 'Name der KWE', parent = form) => {
    const wrapper = new Element('div', {id: `unique-${id}`});
    const nativeLabel = new Element('label', {for: id}, label);
    const input = new Element('input', {id, 'aria-describedby': `${id}-help`});
    input.labels = [nativeLabel];
    const help = new Element('div', {id: `${id}-help`}, 'Hilfetext.');
    wrapper.append(nativeLabel, input, help); parent.append(wrapper);
    return {wrapper, input, label: nativeLabel};
  };
  const ajax = (element, callbacks = {}) => {
    const calls = [];
    const makeCallback = (name, defaultReturn) => function (...args) {
      calls.push({name, thisValue: this, args});
      return callbacks[name] ? callbacks[name].apply(this, args) : defaultReturn;
    };
    const instance = {
      element, url: '/system/ajax',
      progress: {type: 'throbber'},
      beforeSend: makeCallback('beforeSend', 'before-return'),
      success: makeCallback('success', Promise.resolve()),
      error: makeCallback('error', 'error-return'),
      options: {error: makeCallback('options.error', 'options-error-return')},
    };
    Drupal.ajax.instances.push(instance);
    return {instance, calls};
  };
  return {document, form, Drupal, announcements, timers, advance, attach, field, ajax, load, settings: context.drupalSettings};
}

const deferred = () => {
  let resolve, reject;
  const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
  return {promise, resolve, reject};
};
const flush = async () => { for (let index = 0; index < 5; index++) await Promise.resolve(); };
const refs = element => (element.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);

test('only marked forms with supported Drupal progress UI are decorated, once', async () => {
  const env = environment();
  const {input} = env.field();
  const {instance} = env.ajax(input);
  const original = instance.beforeSend;
  const ordinaryForm = new Element('form');
  const ordinaryInput = new Element('input');
  ordinaryForm.append(ordinaryInput); env.document.append(ordinaryForm);
  const unrelated = env.ajax(ordinaryInput).instance;
  const unrelatedBefore = unrelated.beforeSend;
  const bar = env.ajax(input).instance;
  bar.progress.type = 'bar';
  const barBefore = bar.beforeSend;
  env.attach();
  // An instance created by a later behavior in this attachment is included.
  const late = env.ajax(input).instance;
  const lateBefore = late.beforeSend;
  await flush();
  assert.notEqual(instance.beforeSend, original);
  assert.notEqual(late.beforeSend, lateBefore);
  assert.equal(unrelated.beforeSend, unrelatedBefore);
  assert.equal(bar.beforeSend, barBefore);
  const wrapped = [instance.beforeSend, instance.success, instance.error, instance.options.error];
  env.attach(); env.attach(input); await flush();
  assert.deepEqual([instance.beforeSend, instance.success, instance.error, instance.options.error], wrapped);
  assert.equal(env.announcements.length, 0, 'Attachment must stay silent');
});

test('Unique notices keep help IDs, receive collision-free IDs, and attach silently', async () => {
  const env = environment();
  const {wrapper, input} = env.field();
  input.setAttribute('aria-describedby', 'name-help additional-help name-help');
  const collision = new Element('div', {id: 'ddbgo-unique-notice-1'});
  env.document.append(collision);
  const notice = new Element('div', {class: 'warning'}, 'Name bereits vorhanden.');
  wrapper.append(notice);
  env.attach(); await flush();
  assert.ok(notice.id.startsWith('ddbgo-unique-notice-'));
  assert.notEqual(notice.id, collision.id);
  assert.deepEqual(refs(input), ['name-help', 'additional-help', notice.id]);
  const stableId = notice.id;
  env.attach(); await flush();
  assert.equal(notice.id, stableId);
  assert.deepEqual(refs(input), ['name-help', 'additional-help', stableId]);
  assert.equal(env.announcements.length, 0);
  notice.remove(); env.attach(); await flush();
  assert.deepEqual(refs(input), ['name-help', 'additional-help'], 'Only our stale notice reference is removed');
  const supplied = new Element('div', {class: 'error', id: 'server-notice'}, 'Serverhinweis.');
  wrapper.append(supplied); env.attach(); await flush();
  assert.deepEqual(refs(input), ['name-help', 'additional-help', 'server-notice']);
  assert.equal(supplied.id, 'server-notice');
  assert.equal(env.announcements.length, 0);
});

test('hidden inputs and nested unrelated warnings are not mistaken for the Unique notice', async () => {
  const env = environment();
  const {wrapper, input} = env.field();
  const hidden = new Element('input', {type: 'hidden'});
  wrapper.children.unshift(hidden); hidden.parentElement = wrapper;
  const nested = new Element('div');
  const unrelated = new Element('div', {class: 'warning'}, 'Anderer Hinweis.');
  nested.append(unrelated); wrapper.append(nested);
  const notice = new Element('div', {class: 'warning'}, 'Dublettenhinweis.');
  wrapper.append(notice); env.attach(); await flush();
  assert.ok(refs(input).includes(notice.id));
  assert.equal(hidden.getAttribute('aria-describedby'), null);
  assert.equal(unrelated.id, '');
});

test('fast completion has no wait chatter and preserves callback context, args and return', async () => {
  const env = environment();
  const {input} = env.field();
  const {instance, calls} = env.ajax(input, {success: () => 'success-value'});
  env.attach(); await flush();
  const receiver = {context: 'original callback context'};
  const xhr = {xhr: true};
  const options = {options: true};
  assert.equal(instance.beforeSend.call(receiver, xhr, options), 'before-return');
  env.advance(499);
  assert.equal(env.announcements.length, 0);
  const response = [];
  assert.equal(await instance.success.call(receiver, response, 'success'), 'success-value');
  assert.equal(env.announcements.length, 1);
  assert.equal(env.announcements[0].message, 'Name der KWE: Ladevorgang beendet.');
  assert.equal(env.announcements[0].priority, undefined, 'Drupal.announce defaults to polite');
  env.advance(1000);
  assert.equal(env.announcements.length, 1, 'Completed request cannot announce a delayed wait');
  assert.deepEqual(calls.map(call => call.name), ['beforeSend', 'success']);
  assert.equal(calls[0].thisValue, receiver);
  assert.deepEqual(calls[0].args, [xhr, options]);
  assert.equal(calls[1].thisValue, receiver);
  assert.deepEqual(calls[1].args, [response, 'success']);
});

test('add-more buttons, explicit ARIA labels and detached triggers retain useful request context', async () => {
  for (const [tag, attributes, text, expected] of [
    ['input', {type: 'submit', value: 'Kontakt hinzufügen'}, '', 'Kontakt hinzufügen'],
    ['button', {}, 'Einen weiteren Eintrag hinzufügen', 'Einen weiteren Eintrag hinzufügen'],
    ['input', {'aria-label': 'Expliziter Feldname'}, '', 'Expliziter Feldname'],
    ['input', {}, '', 'Formular'],
  ]) {
    const env = environment();
    const element = new Element(tag, attributes, text);
    env.form.append(element);
    const {instance} = env.ajax(element);
    instance.progress.type = 'fullscreen';
    instance.$form = [env.form];
    // Drupal's retained $form is still available when an AJAX trigger detaches.
    element.remove();
    env.attach(); await flush();
    instance.beforeSend({}, {}); await instance.success([], 'success');
    assert.equal(env.announcements[0].message, `${expected}: Ladevorgang beendet.`);
  }
});

test('a long request announces waiting, then its final notice only after commands resolve', async () => {
  const env = environment();
  const {wrapper, input} = env.field();
  const command = deferred();
  const {instance} = env.ajax(input, {success: () => command.promise});
  env.attach(); await flush(); instance.beforeSend({}, {});
  env.advance(500);
  assert.deepEqual(env.announcements.map(item => item.message), ['Name der KWE: Bitte warten.']);
  const finished = instance.success([], 'success');
  await flush();
  assert.equal(env.announcements.length, 1, 'HTTP success does not mean response commands have completed');
  const notice = new Element('div', {class: 'warning'}, 'Der Name existiert bereits.');
  wrapper.append(notice);
  command.resolve('commands-finished');
  assert.equal(await finished, 'commands-finished');
  assert.deepEqual(env.announcements.map(item => item.message), [
    'Name der KWE: Bitte warten.', 'Name der KWE: Der Name existiert bereits.',
  ]);
  assert.ok(refs(input).includes(notice.id));
  env.attach(); await flush();
  assert.equal(env.announcements.length, 2, 'Behavior reattachment never repeats the notice');
});

test('each request announces its own result even when identical to the previous result', async () => {
  const env = environment();
  const {wrapper, input} = env.field();
  const notice = new Element('div', {class: 'warning'}, 'Bereits vorhanden.');
  wrapper.append(notice);
  const {instance} = env.ajax(input);
  env.attach(); await flush();
  for (let index = 0; index < 2; index++) {
    instance.beforeSend({}, {}); await instance.success([], 'success');
  }
  assert.deepEqual(env.announcements.map(item => item.message), [
    'Name der KWE: Bereits vorhanden.', 'Name der KWE: Bereits vorhanden.',
  ]);
});

test('the result links a newly replaced input and observes newly created AJAX instances', async () => {
  const env = environment();
  const field = env.field();
  const command = deferred();
  const {instance} = env.ajax(field.input, {success: () => command.promise});
  env.attach(); await flush(); instance.beforeSend({}, {});
  const finished = instance.success([], 'success');
  field.wrapper.remove();
  const replacement = env.field('name');
  const notice = new Element('div', {class: 'warning'}, 'Hinweis zur neuen Eingabe.');
  replacement.wrapper.append(notice);
  const next = env.ajax(replacement.input).instance;
  const before = next.beforeSend;
  env.attach(replacement.wrapper); await flush();
  assert.notEqual(next.beforeSend, before);
  assert.equal(env.announcements.length, 0, 'Replacing markup does not itself announce');
  command.resolve(); await finished;
  assert.equal(env.announcements[0].message, 'Name der KWE: Hinweis zur neuen Eingabe.');
  assert.ok(refs(replacement.input).includes(notice.id));
  next.beforeSend({}, {}); await next.success([], 'success');
  assert.equal(env.announcements.length, 2);
});

test('concurrent requests use their own trigger labels and notice wrappers', async () => {
  const env = environment();
  const name = env.field('name', 'Name');
  const uri = env.field('uri', 'DDB-URI');
  const first = deferred(), second = deferred();
  const a = env.ajax(name.input, {success: () => first.promise}).instance;
  const b = env.ajax(uri.input, {success: () => second.promise}).instance;
  env.attach(); await flush(); a.beforeSend({}, {}); b.beforeSend({}, {});
  const doneA = a.success([], 'success');
  const doneB = b.success([], 'success');
  uri.wrapper.append(new Element('div', {class: 'warning'}, 'URI bereits vorhanden.'));
  second.resolve(); await doneB;
  assert.deepEqual(env.announcements.map(item => item.message), ['DDB-URI: URI bereits vorhanden.']);
  name.wrapper.append(new Element('div', {class: 'warning'}, 'Name bereits vorhanden.'));
  first.resolve(); await doneA;
  assert.deepEqual(env.announcements.map(item => item.message), [
    'DDB-URI: URI bereits vorhanden.', 'Name: Name bereits vorhanden.',
  ]);
});

test('a stale response cannot finish a newer request on the same instance', async () => {
  const env = environment();
  const {input} = env.field();
  const first = deferred(), second = deferred();
  let count = 0;
  const {instance} = env.ajax(input, {success: () => (++count === 1 ? first.promise : second.promise)});
  env.attach(); await flush(); instance.beforeSend({}, {});
  const old = instance.success([], 'success');
  instance.beforeSend({}, {});
  const current = instance.success([], 'success');
  first.resolve('old'); assert.equal(await old, 'old');
  assert.equal(env.announcements.length, 0);
  env.advance(500);
  assert.equal(env.announcements[0].message, 'Name der KWE: Bitte warten.');
  second.resolve('current'); assert.equal(await current, 'current');
  assert.equal(env.announcements[1].message, 'Name der KWE: Ladevorgang beendet.');
});

test('Core announce/message commands suppress only the redundant completion message', async () => {
  for (const command of ['announce', 'message']) {
    const env = environment();
    const {input} = env.field();
    const {instance} = env.ajax(input);
    env.attach(); await flush(); instance.beforeSend({}, {});
    await instance.success([{command, message: 'Core handles this message'}], 'success');
    assert.equal(env.announcements.length, 0, command);
    env.advance(1000); assert.equal(env.announcements.length, 0);
  }
});

test('detached forms do not produce wait or completion announcements', async () => {
  const env = environment();
  const {input} = env.field();
  const command = deferred();
  const {instance} = env.ajax(input, {success: () => command.promise});
  env.attach(); await flush(); instance.beforeSend({}, {});
  const finished = instance.success([], 'success');
  env.form.remove(); env.advance(1000);
  assert.equal(env.announcements.length, 0);
  command.resolve(); await finished;
  assert.equal(env.announcements.length, 0);
  assert.equal(env.timers.size, 0);
});

test('labels and notice text are escaped for Drupal.announce HTML handling', async () => {
  const env = environment();
  const {wrapper, input} = env.field('name', 'Name <KWE> & A');
  wrapper.append(new Element('div', {class: 'warning'}, 'Vorhanden: <img src=x> & Test'));
  const {instance} = env.ajax(input);
  env.attach(); await flush(); instance.beforeSend({}, {});
  env.advance(500);
  await instance.success([], 'success');
  assert.deepEqual(env.announcements.map(item => item.message), [
    'Name &lt;KWE&gt; &amp; A: Bitte warten.',
    'Name &lt;KWE&gt; &amp; A: Vorhanden: &lt;img src=x&gt; &amp; Test',
  ]);
});

test('aborts, network errors and token failures clean up while preserving Core callbacks', async () => {
  for (const status of ['abort', 'error', 'parsererror']) {
    const env = environment();
    const {input} = env.field();
    const {instance, calls} = env.ajax(input);
    env.attach(); await flush(); instance.beforeSend({}, {});
    const receiver = {context: status};
    const xhr = {status: 0};
    assert.equal(instance.options.error.call(receiver, xhr, status, 'failure'), 'options-error-return');
    // Core's complete handler calls ajax.error separately for non-abort errors.
    if (status !== 'abort') {
      assert.equal(instance.error.call(receiver, xhr, '/system/ajax', 'verification-failure'), 'error-return');
      assert.equal(calls.at(-1).thisValue, receiver);
      assert.deepEqual(calls.at(-1).args, [xhr, '/system/ajax', 'verification-failure']);
    }
    assert.equal(calls[1].thisValue, receiver);
    assert.deepEqual(calls[1].args, [xhr, status, 'failure']);
    env.advance(1000);
    assert.equal(env.announcements.length, 0, 'Core owns the error announcement');
    assert.equal(env.timers.size, 0);
  }
  const env = environment();
  const {input} = env.field();
  const {instance} = env.ajax(input);
  env.attach(); await flush(); instance.beforeSend({}, {});
  // Failed token verification calls ajax.error without calling ajax.success.
  instance.error({}, '/system/ajax', 'The response failed verification.');
  env.advance(1000);
  assert.equal(env.announcements.length, 0);
});

test('exceptions/rejected command promises propagate and cancel pending wait timers', async () => {
  for (const asynchronous of [false, true]) {
    const env = environment();
    const {input} = env.field();
    const failure = new Error('Original AJAX command failed');
    const {instance} = env.ajax(input, {success() {
      if (asynchronous) return Promise.reject(failure);
      throw failure;
    }});
    env.attach(); await flush(); instance.beforeSend({}, {});
    if (asynchronous) await assert.rejects(instance.success([], 'success'), error => error === failure);
    else assert.throws(() => instance.success([], 'success'), error => error === failure);
    env.advance(1000);
    assert.equal(env.announcements.length, 0);
    assert.equal(env.timers.size, 0);
  }
  const env = environment();
  const {input} = env.field();
  const failure = new Error('Original Core error');
  const {instance} = env.ajax(input, {error() { throw failure; }});
  env.attach(); await flush(); instance.beforeSend({}, {});
  assert.throws(() => instance.error({}, '/system/ajax'), error => error === failure);
  env.advance(1000); assert.equal(env.announcements.length, 0);
  const second = env.ajax(input, {'options.error'() { throw failure; }}).instance;
  env.attach(); await flush(); second.beforeSend({}, {});
  assert.throws(() => second.options.error({}, 'error'), error => error === failure);
  env.advance(1000); assert.equal(env.announcements.length, 0);
  const third = env.ajax(input, {beforeSend() { throw failure; }}).instance;
  env.attach(); await flush();
  assert.throws(() => third.beforeSend({}, {}), error => error === failure);
  env.advance(1000); assert.equal(env.announcements.length, 0);
});

test('cancelled beforeSend stays cancelled and never creates a wait announcement', async () => {
  const env = environment();
  const {input} = env.field();
  const {instance, calls} = env.ajax(input, {beforeSend: () => false});
  env.attach(); await flush();
  assert.equal(instance.beforeSend({}, {}), false);
  env.advance(1000);
  assert.equal(calls.length, 1);
  assert.equal(env.announcements.length, 0);
  assert.equal(env.timers.size, 0);
});

test('existing Unique Field submit guard still cancels a queued save exactly once', async () => {
  const env = environment();
  const {input} = env.field();
  const button = new Element('input', {type: 'submit', id: 'save', value: 'Speichern'});
  env.form.append(button);
  env.settings.unique_field_ajax = [{id: '#name'}];
  env.load(path.resolve(__dirname, '../../js/ddbgo_gin.unique-field-submit.js'));
  const {instance, calls} = env.ajax(input);
  instance.ajaxing = true;
  env.attach(); await flush(); instance.beforeSend({}, {});
  let prevented = 0;
  env.document.dispatchEvent({type: 'submit', target: env.form, submitter: button,
    preventDefault() { prevented++; }, stopImmediatePropagation() {},
  });
  assert.equal(prevented, 1);
  assert.equal(button.getAttribute('aria-disabled'), 'true');
  instance.options.error({}, 'error', 'failure');
  assert.equal(button.getAttribute('aria-disabled'), null);
  assert.equal(calls.filter(call => call.name === 'options.error').length, 1);
  assert.deepEqual(env.announcements.map(item => item.message), [
    'Die Prüfung wurde nicht abgeschlossen. Bitte erneut speichern.',
  ]);
  env.advance(1000);
  assert.equal(env.announcements.length, 1, 'Submit guard remains the sole cancellation announcement');
});
