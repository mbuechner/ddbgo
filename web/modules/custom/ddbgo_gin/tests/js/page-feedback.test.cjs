/**
 * Run: node --test web/modules/custom/ddbgo_gin/tests/js/page-feedback.test.cjs
 *
 * Dependency-free checks of message selection, focus, lifecycle and escaping.
 * This tests browser API contracts, not an actual screenreader's output.
 */
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../js/ddbgo_gin.page-feedback.js');

function environment() {
  const announcements = [];
  const messages = [];
  const reservedIds = new Set();
  const microtasks = [];
  const document = {
    body: {tagName: 'BODY'},
    documentElement: {tagName: 'HTML'},
    querySelectorAll(selector) {
      assert.equal(selector, '.messages[data-ddbgo-page-messages]');
      return messages.filter(message => message.marked);
    },
    getElementById(id) {
      return messages.map(message => message.content).find(content => content?.id === id)
        || (reservedIds.has(id) ? {id} : null);
    },
  };
  document.activeElement = document.body;
  const Drupal = {
    behaviors: {},
    announce: (message, priority) => announcements.push({message, priority}),
    checkPlain: value => String(value).replace(/[&<>"']/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[character])),
  };
  const window = {queueMicrotask: callback => microtasks.push(callback)};
  vm.runInNewContext(fs.readFileSync(sourcePath, 'utf8'), {document, Drupal, window}, {filename: sourcePath});
  const attach = (context = document) => Drupal.behaviors.ddbgoPageFeedback.attach(context);
  const flush = () => {
    while (microtasks.length) microtasks.shift()();
  };
  const message = (type, text, marked = true) => {
    const attributes = new Map();
    const element = {
      marked, type, isConnected: true, focusCount: 0,
      // The title and dismiss label deliberately differ from the body text.
      textContent: `Fehlermeldung ${text} Ausblenden`,
      content: text === null ? null : {textContent: text, id: ''},
      classList: {contains: name => name === `messages--${type}`},
      getAttribute: name => attributes.has(name) ? attributes.get(name) : null,
      setAttribute: (name, value) => attributes.set(name, String(value)),
      querySelector(selector) {
        assert.equal(selector, '.messages__content');
        return this.content;
      },
      focus() { this.focusCount++; document.activeElement = this; },
    };
    if (element.content) {
      Object.defineProperty(element.content, 'isConnected', {get: () => element.isConnected});
    }
    messages.push(element);
    return element;
  };
  return {document, announcements, messages, reservedIds, microtasks, attach, flush, message};
}

test('an initial error summary gets focus after initial behaviors, with no duplicate announcement', () => {
  const env = environment();
  const summary = env.message('error', 'Speichern fehlgeschlagen. Person auswählen.');
  env.attach();
  assert.equal(summary.focusCount, 0, 'Focus must wait for other initial behaviors');
  assert.equal(env.microtasks.length, 1);
  env.flush();
  assert.equal(summary.focusCount, 1);
  assert.equal(env.document.activeElement, summary);
  assert.equal(summary.content.id, 'ddbgo-page-error-1');
  assert.equal(summary.getAttribute('aria-describedby'), summary.content.id);
  assert.deepEqual(env.announcements, []);
  env.attach(); env.attach(summary); env.flush();
  assert.equal(summary.focusCount, 1, 'Repeated attachment must not refocus');
  assert.deepEqual(env.announcements, []);
});

test('errors take priority over success and warning notices, regardless of document order', () => {
  const env = environment();
  env.message('status', 'Gespeichert.');
  env.message('warning', 'Bitte prüfen.');
  const first = env.message('error', 'Es wurden Fehler gefunden.');
  const second = env.message('error', 'Weiterer Fehler.');
  env.attach(); env.flush();
  assert.equal(first.focusCount, 1);
  assert.equal(second.focusCount, 0);
  assert.deepEqual(env.announcements, []);
});

test('existing input focus is preserved and the error is announced instead', () => {
  for (const moment of ['before-attach', 'before-microtask']) {
    const env = environment();
    const summary = env.message('error', 'Fehler.');
    const input = {tagName: 'INPUT'};
    if (moment === 'before-attach') env.document.activeElement = input;
    env.attach();
    if (moment === 'before-microtask') env.document.activeElement = input;
    env.flush();
    assert.equal(env.document.activeElement, input, moment);
    assert.equal(summary.focusCount, 0, moment);
    assert.equal(summary.content.id, '', 'Do not mutate feedback when a control already has focus');
    assert.equal(summary.getAttribute('aria-describedby'), null);
    assert.deepEqual(env.announcements, [{message: 'Fehler.', priority: 'assertive'}]);
    env.attach(); env.flush();
    assert.equal(env.announcements.length, 1, 'Fallback error feedback also runs only once');
  }
});

test('an already focused summary does not get a second focus event', () => {
  const env = environment();
  const summary = env.message('error', 'Fehler.');
  env.document.activeElement = summary;
  env.attach(); env.flush();
  assert.equal(summary.focusCount, 0);
  assert.deepEqual(env.announcements, []);
});

test('an unfocused document or its html element permits error focus', () => {
  for (const initial of ['html', 'null']) {
    const env = environment();
    const summary = env.message('error', 'Fehler.');
    env.document.activeElement = initial === 'html' ? env.document.documentElement : null;
    env.attach(); env.flush();
    assert.equal(summary.focusCount, 1, initial);
    assert.equal(env.document.activeElement, summary, initial);
  }
});

test('error descriptions preserve existing references and reuse a supplied content ID', () => {
  const env = environment();
  const summary = env.message('error', 'Ungültige Eingaben.');
  summary.content.id = 'server-error-content';
  summary.setAttribute('aria-describedby', 'existing-help more-context existing-help');
  summary.setAttribute('aria-labelledby', 'gin-error-title');
  env.attach(); env.flush();
  assert.equal(summary.content.id, 'server-error-content');
  assert.equal(summary.getAttribute('aria-describedby'), 'existing-help more-context server-error-content');
  assert.equal(summary.getAttribute('aria-labelledby'), 'gin-error-title', 'Keep Gin\'s accessible name');
  assert.equal(summary.focusCount, 1);
});

test('generated error-content IDs avoid document collisions', () => {
  const env = environment();
  env.reservedIds.add('ddbgo-page-error-1');
  env.reservedIds.add('ddbgo-page-error-2');
  const summary = env.message('error', 'Fehler.');
  env.attach(); env.flush();
  assert.equal(summary.content.id, 'ddbgo-page-error-3');
  assert.equal(summary.getAttribute('aria-describedby'), 'ddbgo-page-error-3');
  assert.equal(summary.focusCount, 1);
});

test('an existing error-content description is not duplicated', () => {
  const env = environment();
  const summary = env.message('error', 'Fehler.');
  summary.content.id = 'existing-error-body';
  summary.setAttribute('aria-describedby', 'extra-help existing-error-body');
  env.attach(); env.flush();
  assert.equal(summary.getAttribute('aria-describedby'), 'extra-help existing-error-body');
  assert.equal(summary.focusCount, 1);
});

test('missing or empty error bodies never focus an uninformative error box', () => {
  for (const body of [null, ' \n\t ']) {
    const env = environment();
    const summary = env.message('error', body);
    env.message('status', 'Gespeichert.');
    env.attach(); env.flush();
    assert.equal(summary.focusCount, 0);
    assert.equal(summary.getAttribute('aria-describedby'), null);
    assert.deepEqual(env.announcements, [], 'An error still takes precedence over a success notice');
  }
});

test('fallback error text is escaped and never accompanies error focus', () => {
  const env = environment();
  const summary = env.message('error', 'Ungültig: <b>Person</b> & \'Name\'.');
  const input = {tagName: 'INPUT'};
  env.document.activeElement = input;
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{
    message: 'Ungültig: &lt;b&gt;Person&lt;/b&gt; &amp; &#39;Name&#39;.',
    priority: 'assertive',
  }]);
  assert.equal(summary.focusCount, 0);
  assert.equal(env.document.activeElement, input);
});

test('a success notice announces only its content, politely, without moving focus', () => {
  const env = environment();
  const success = env.message('status', '  Person wurde gespeichert.  ');
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{message: 'Person wurde gespeichert.', priority: 'polite'}]);
  assert.equal(env.document.activeElement, env.document.body);
  assert.equal(success.focusCount, 0);
});

test('warnings use assertive priority and several notice groups are announced once in order', () => {
  const env = environment();
  env.message('status', 'Eintrag gespeichert.');
  env.message('warning', 'Verknüpfung bitte prüfen.');
  env.message('status', 'Datei hinzugefügt.');
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{
    message: 'Eintrag gespeichert.\nVerknüpfung bitte prüfen.\nDatei hinzugefügt.',
    priority: 'assertive',
  }]);
});

test('plain message text is escaped before it reaches Drupal.announce innerHTML', () => {
  const env = environment();
  env.message('status', 'Gespeichert: <img src=x onerror="alert(1)"> & \'Name\'.');
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{
    message: 'Gespeichert: &lt;img src=x onerror=&quot;alert(1)&quot;&gt; &amp; &#39;Name&#39;.',
    priority: 'polite',
  }]);
});

test('unmarked dynamic Drupal/Gin messages and unsupported types stay silent', () => {
  const env = environment();
  const ajaxError = env.message('error', 'AJAX-Fehler.', false);
  env.message('status', 'Core hat dies bereits angesagt.', false);
  env.message('custom', 'Eigene Meldung.');
  env.attach(); env.flush();
  assert.equal(ajaxError.focusCount, 0);
  assert.deepEqual(env.announcements, []);
});

test('an AJAX context cannot consume initial feedback or read a replacement message', () => {
  const env = environment();
  const status = env.message('status', 'Initiale Meldung.');
  env.attach(status); env.flush();
  assert.deepEqual(env.announcements, []);
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{message: 'Initiale Meldung.', priority: 'polite'}]);
  env.message('error', 'Per AJAX eingesetzte Meldung.');
  env.attach(status); env.attach(); env.flush();
  assert.equal(env.announcements.length, 1);
  assert.equal(env.document.activeElement, env.document.body);
});

test('an initially empty document never announces messages added during later attachment', () => {
  const env = environment();
  env.attach(); env.flush();
  const late = env.message('error', 'Späterer Fehler.');
  env.attach(); env.attach(late); env.flush();
  assert.equal(late.focusCount, 0);
  assert.deepEqual(env.announcements, []);
  assert.equal(env.microtasks.length, 0);
});

test('only messages present at initial attachment participate in its deferred feedback', () => {
  const env = environment();
  env.message('status', 'Initiale Meldung.');
  env.attach();
  const later = env.message('error', 'Von einem späteren Behavior eingefügt.');
  env.flush();
  assert.equal(later.focusCount, 0);
  assert.deepEqual(env.announcements, [{message: 'Initiale Meldung.', priority: 'polite'}]);
});

test('a message removed before feedback is neither focused nor announced', () => {
  for (const type of ['error', 'status', 'warning']) {
    const env = environment();
    const removed = env.message(type, 'Bereits entfernt.');
    env.attach(); removed.isConnected = false; env.flush();
    assert.equal(removed.focusCount, 0, type);
    assert.deepEqual(env.announcements, [], type);
  }
});

test('empty or missing notice bodies do not produce title/dismiss-button chatter', () => {
  const env = environment();
  env.message('status', ' \n\t ');
  env.message('status', null);
  env.attach(); env.flush();
  assert.deepEqual(env.announcements, []);
});

test('document reuse such as bfcache restoration does not repeat the original announcement', () => {
  const env = environment();
  env.message('status', 'Erfolgreich gespeichert.');
  env.attach(); env.flush();
  env.attach(); env.attach(); env.flush();
  assert.deepEqual(env.announcements, [{message: 'Erfolgreich gespeichert.', priority: 'polite'}]);
});
