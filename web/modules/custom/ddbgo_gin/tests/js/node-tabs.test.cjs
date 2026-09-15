const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const script = fs.readFileSync(path.join(__dirname, '../../js/ddbgo_gin.node-tabs.js'), 'utf8');

// Small DOM/Field Group adapter: exercise the shipped behavior's navigation
// decisions without a browser or a Drupal database. Visual QA remains separate.
// Only the DOM operations used by the behavior are modeled. These tests do not
// verify real CSS selectors, browser event propagation or Field Group itself.
// Each page() creates an isolated JS context, as a full page navigation would.
function page({ ids = ['edit-group-status', 'edit-group-informationen'], hash = '', mobile = false, errors = false } = {}) {
  const events = new Map();
  const queued = [];
  const panes = ids.map((id) => ({ id, open: false, selected: false, hidden: false, parent: null }));
  const links = [
    { href: 'https://ddbgo.test/kwe/example' },
    { href: 'https://ddbgo.test/node/42/edit?destination=/search/kwe' },
    { href: 'https://ddbgo.test/node/43/edit' },
    { href: 'https://elsewhere.test/node/42/edit' },
    { href: 'https://ddbgo.test/node/42/edit#specific-field' },
  ];
  for (const pane of panes) {
    pane.closest = (selector) => {
      if (selector === '.horizontal-tab-hidden') {
        return pane.hidden ? pane : pane.parent?.closest(selector);
      }
      return pane;
    };
    pane.parentElement = { closest: () => pane.parent };
    pane.matches = () => true;
    if (!mobile) {
      pane.tab = {
        item: { hasClass: () => pane.selected },
        focus: () => {
          panes.filter((sibling) => sibling.parent === pane.parent).forEach((sibling) => {
            sibling.selected = sibling === pane;
            sibling.hidden = sibling !== pane;
          });
        },
      };
    }
  }
  if (mobile) panes[0].open = true;
  else panes[0].tab.focus();

  const root = {
    querySelectorAll: () => panes,
    querySelector: () => errors ? {} : null,
  };
  const document = {
    baseURI: 'https://ddbgo.test/node/42/edit',
    querySelector: () => root,
    querySelectorAll: () => links,
    addEventListener: (name, listener) => {
      if (!events.has(name)) events.set(name, []);
      events.get(name).push(listener);
    },
  };
  const Drupal = { behaviors: {} };
  let initialized = false;
  vm.runInNewContext(script, {
    URL, document, Drupal,
    window: { location: { hash }, queueMicrotask: (callback) => queued.push(callback) },
    jQuery: (pane) => ({ data: () => pane.tab }),
    once: () => {
      if (initialized) return [];
      initialized = true;
      return [document];
    },
    drupalSettings: { ddbgoNodeTabs: { links: [links[0].href, 'https://ddbgo.test/node/42/edit'] } },
  });
  const attach = () => {
    // Flush deferred work after attach, matching the behavior's microtask order.
    // Calling this again simulates an AJAX attach in the same document.
    Drupal.behaviors.ddbgoNodeTabs.attach(document);
    while (queued.length) queued.shift()();
  };
  const emit = (name, event = {}) => events.get(name)?.forEach((callback) => callback(event));
  return { attach, emit, panes, links, events };
}

test('selected section follows view/edit links, preserving destination and unrelated links', () => {
  const p = page();
  p.attach();
  p.panes[1].tab.focus();
  p.emit('click');
  assert.equal(new URL(p.links[0].href).hash, '#ddbgo-tab=edit-group-informationen');
  assert.equal(new URL(p.links[1].href).hash, '#ddbgo-tab=edit-group-informationen');
  assert.equal(new URL(p.links[1].href).searchParams.get('destination'), '/search/kwe');
  assert.equal(p.links[2].href, 'https://ddbgo.test/node/43/edit');
  assert.equal(p.links[3].href, 'https://elsewhere.test/node/42/edit');
  assert.equal(new URL(p.links[4].href).hash, '#specific-field');
});

test('incoming section overrides the default; AJAX does not restore it again', () => {
  const p = page({ hash: '#ddbgo-tab=edit-group-informationen' });
  p.attach();
  assert.equal(p.panes[1].selected, true);
  p.panes[0].tab.focus();
  p.links.push({ href: 'https://ddbgo.test/node/42/edit' });
  p.attach();
  assert.equal(p.panes[0].selected, true);
  assert.equal(new URL(p.links.at(-1).href).hash, '#ddbgo-tab=edit-group-status');
  assert.equal(p.events.get('click').length, 1);
});

test('validation errors, missing tabs and unrelated anchors retain Drupal selection', () => {
  for (const options of [
    { hash: '#ddbgo-tab=edit-group-informationen', errors: true },
    { hash: '#ddbgo-tab=does-not-exist' },
    { hash: '#specific-field' },
  ]) {
    const p = page(options);
    p.attach();
    assert.equal(p.panes[0].selected, true);
  }
});

test('legacy view/form group names and AJAX ID suffixes resolve to the same section', () => {
  for (const [id, key] of [
    ['edit-group-s', 'edit-group-status'],
    ['edit-group-geografische-ausrichtung', 'edit-group-ausrichtung'],
    ['edit-group-europenana-archivportal', 'edit-group-europeana-archivportal'],
    ['edit-group-informationen--ajax-id', 'edit-group-informationen'],
  ]) {
    const p = page({ ids: ['edit-first', id], hash: `#ddbgo-tab=${key}` });
    p.attach();
    assert.equal(p.panes[1].selected, true);
    assert.equal(new URL(p.links[1].href).hash, `#ddbgo-tab=${key}`);
  }
});

test('nested section restores its ancestors and later nested choices are retained', () => {
  const p = page({ ids: ['edit-first', 'edit-parent', 'edit-child'], hash: '#ddbgo-tab=edit-child' });
  p.panes[2].parent = p.panes[1];
  p.attach();
  assert.equal(p.panes[1].selected, true);
  assert.equal(p.panes[2].selected, true);
  assert.equal(new URL(p.links[1].href).hash, '#ddbgo-tab=edit-child');

  const parent = page({ ids: ['edit-first', 'edit-parent', 'edit-child'], hash: '#ddbgo-tab=edit-parent' });
  parent.panes[2].parent = parent.panes[1];
  parent.attach();
  parent.panes[2].tab.focus();
  parent.emit('click');
  assert.equal(new URL(parent.links[1].href).hash, '#ddbgo-tab=edit-child');
});

test('mobile details open on arrival and follow the most recently opened section', () => {
  const p = page({ mobile: true, hash: '#ddbgo-tab=edit-group-informationen' });
  p.attach();
  assert.equal(p.panes[1].open, true);
  p.emit('toggle', { target: p.panes[0] });
  assert.equal(new URL(p.links[1].href).hash, '#ddbgo-tab=edit-group-status');
});

test('keyboard and new-tab interactions use the current section', () => {
  for (const event of ['keydown', 'auxclick', 'contextmenu']) {
    const p = page();
    p.attach();
    p.panes[1].tab.focus();
    p.emit(event);
    assert.equal(new URL(p.links[1].href).hash, '#ddbgo-tab=edit-group-informationen');
  }
});
