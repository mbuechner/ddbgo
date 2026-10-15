'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../../js/ddbgo_gin.scrollsync.js'), 'utf8');

class Scroller {
  constructor(group, width, height = width, legacy = false) {
    this.attributes = new Map([[legacy ? 'name' : 'data-syncscroll', group]]);
    this.listeners = new Set();
    this.clientWidth = 100;
    this.scrollWidth = width + 100;
    this.clientHeight = 100;
    this.scrollHeight = height + 100;
    this.x = 0;
    this.y = 0;
  }

  getAttribute(name) {
    return this.attributes.get(name) ?? null;
  }

  addEventListener(type, listener) {
    assert.equal(type, 'scroll');
    this.listeners.add(listener);
  }

  removeEventListener(type, listener) {
    assert.equal(type, 'scroll');
    this.listeners.delete(listener);
  }

  get scrollLeft() {
    return this.x;
  }

  set scrollLeft(value) {
    assert.ok(Number.isFinite(value), 'scrollLeft must remain finite');
    if (this.x !== value) {
      this.x = value;
      this.dispatchScroll();
    }
  }

  get scrollTop() {
    return this.y;
  }

  set scrollTop(value) {
    assert.ok(Number.isFinite(value), 'scrollTop must remain finite');
    if (this.y !== value) {
      this.y = value;
      this.dispatchScroll();
    }
  }

  dispatchScroll() {
    // Synchronous events also expose any accidental feedback recursion.
    Array.from(this.listeners).forEach((listener) => listener());
  }

  scrollTo(x, y) {
    this.x = x;
    this.y = y;
    this.dispatchScroll();
  }
}

function load(elements, readyState = 'complete') {
  const window = {
    listeners: new Map(),
    addEventListener(type, listener) {
      this.listeners.set(type, listener);
    },
  };
  const document = {
    elements,
    readyState,
    getElementsByClassName(name) {
      assert.equal(name, 'syncscroll');
      return this.elements;
    },
  };
  const Drupal = { behaviors: {} };
  vm.runInNewContext(script, { window, document, Drupal });
  return { window, document, behavior: Drupal.behaviors.ddbgoScrollSync };
}

test('two table pairs synchronize both axes proportionally and independently', () => {
  const tableA = new Scroller('table-a', 800, 600);
  const headerA = new Scroller('table-a', 400, 300);
  const tableB = new Scroller('table-b', 900, 300);
  const headerB = new Scroller('table-b', 300, 150);
  // An inherited legacy name must not override each pair's data attribute.
  [tableA, headerA, tableB, headerB].forEach((element) => {
    element.attributes.set('name', 'gin-sticky-header');
  });
  load([tableA, headerA, tableB, headerB]);

  tableA.scrollTo(200, 300);
  assert.deepEqual([headerA.scrollLeft, headerA.scrollTop], [100, 150]);
  assert.deepEqual([tableB.scrollLeft, headerB.scrollLeft], [0, 0]);
  headerA.scrollTo(300, 75);
  assert.deepEqual([tableA.scrollLeft, tableA.scrollTop], [600, 150]);

  tableB.scrollTo(450, 150);
  assert.deepEqual([headerB.scrollLeft, headerB.scrollTop], [150, 75]);
  assert.deepEqual([tableA.scrollLeft, headerA.scrollLeft], [600, 300]);
});

test('reset removes old listeners and finds AJAX replacements without duplication', () => {
  const oldTable = new Scroller('pair', 800);
  const oldHeader = new Scroller('pair', 400);
  const state = load([oldTable, oldHeader]);
  state.window.syncscroll.reset();
  state.window.syncscroll.reset();
  assert.equal(oldTable.listeners.size, 1);
  assert.equal(oldHeader.listeners.size, 1);

  const newTable = new Scroller('pair', 600);
  const newHeader = new Scroller('pair', 300);
  state.document.elements = [newTable, newHeader];
  state.window.syncscroll.reset();
  assert.equal(oldTable.listeners.size, 0);
  assert.equal(oldHeader.listeners.size, 0);
  assert.equal(newTable.listeners.size, 1);
  oldTable.scrollLeft = 200;
  assert.equal(oldHeader.scrollLeft, 0);
  newTable.scrollLeft = 300;
  assert.equal(newHeader.scrollLeft, 150);
});

test('AJAX detach releases a subtree while preserving other active pairs', () => {
  const tableA = new Scroller('a', 800);
  const headerA = new Scroller('a', 400);
  const tableB = new Scroller('b', 600);
  const headerB = new Scroller('b', 300);
  const state = load([tableA, headerA, tableB, headerB]);
  const subtree = { contains: (element) => element === tableA || element === headerA };

  state.behavior.detach(subtree, {}, 'serialize');
  assert.equal(tableA.listeners.size, 1);
  state.behavior.detach(subtree, {}, 'unload');
  assert.equal(tableA.listeners.size, 0);
  assert.equal(headerA.listeners.size, 0);
  assert.equal(tableB.listeners.size, 1);
  tableB.scrollLeft = 300;
  assert.equal(headerB.scrollLeft, 150);

  const replacement = new Scroller('a', 1000);
  const replacementHeader = new Scroller('a', 500);
  state.document.elements = [replacement, replacementHeader, tableB, headerB];
  state.behavior.attach();
  replacement.scrollLeft = 400;
  assert.equal(replacementHeader.scrollLeft, 200);
  assert.equal(headerB.listeners.size, 1);
});

test('legacy name groups, scroller overrides, and zero overflow remain supported', () => {
  const wrapper = new Scroller('legacy', 0, 0, true);
  const scroller = new Scroller('unused', 400, 200);
  wrapper.scroller = scroller;
  const header = new Scroller('legacy', 200, 0, true);
  const empty = new Scroller('legacy', 0, 0, true);
  const ungrouped = new Scroller('', 800);
  load([wrapper, wrapper, header, empty, ungrouped]);

  assert.equal(wrapper.listeners.size, 0);
  assert.equal(scroller.listeners.size, 1);
  assert.equal(ungrouped.listeners.size, 0);
  scroller.scrollTo(200, 100);
  assert.deepEqual([header.scrollLeft, header.scrollTop], [100, 0]);
  assert.deepEqual([empty.scrollLeft, empty.scrollTop], [0, 0]);
  header.scrollLeft = 150;
  assert.equal(scroller.scrollLeft, 300);
});

test('initialization waits for load and resets safely after earlier Drupal attach', () => {
  const table = new Scroller('pair', 800);
  const header = new Scroller('pair', 400);
  const state = load([table, header], 'loading');
  assert.equal(table.listeners.size, 0);
  state.behavior.attach();
  table.scrollLeft = 200;
  assert.equal(header.scrollLeft, 100);
  state.window.listeners.get('load')();
  assert.equal(table.listeners.size, 1);
  header.scrollLeft = 200;
  assert.equal(table.scrollLeft, 400);
});
