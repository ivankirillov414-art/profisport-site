const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../motion.js'), 'utf8');
function setup({ reduced = false, missing = false, failure = false, hash = '' } = {}) {
  const handlers = {}, windowHandlers = {}, observed = new Set();
  const element = (top, id) => ({
    id, hidden: false, classes: new Set(), listeners: {},
    classList: { add(name) { this.owner.classes.add(name); }, remove(name) { this.owner.classes.delete(name); } },
    getClientRects: () => [1], getBoundingClientRect: () => ({ top }),
    contains(other) { return other === this; },
    addEventListener(name, fn) { this.listeners[name] = fn; },
    removeEventListener(name) { delete this.listeners[name]; }
  });
  const elements = [element(10, 'hero'), element(950, 'service'), element(1600, 'map')];
  elements.forEach(el => el.classList.owner = el);
  let callback, mediaChange, disconnected = false;
  const preference = { matches: reduced, addEventListener: (_, fn) => mediaChange = fn };
  const document = {
    readyState: 'complete', activeElement: null,
    querySelectorAll(selector) { return selector === '.motion-enter' ? elements.filter(e => e.classes.has('motion-enter')) : elements; },
    getElementById(id) { return elements.find(e => e.id === id); },
    addEventListener(name, fn) { handlers[name] = fn; }
  };
  class Observer {
    constructor(fn) { callback = fn; }
    observe(el) { if (failure) throw Error('observer failure'); observed.add(el); }
    unobserve(el) { observed.delete(el); }
    disconnect() { observed.clear(); disconnected = true; }
  }
  const window = { addEventListener(name, fn) { windowHandlers[name] = fn; } };
  if (!missing) window.IntersectionObserver = Observer;
  vm.runInNewContext(source, { document, window, matchMedia: () => preference, IntersectionObserver: Observer, innerHeight: 800, location: { hash } });
  return { elements, observed, handlers, windowHandlers, enter(el) { callback([{ target: el, isIntersecting: true }]); }, reduce() { preference.matches = true; mediaChange(); }, get disconnected() { return disconnected; } };
}
for (const options of [{ reduced: true }, { missing: true }, { failure: true }]) {
  const t = setup(options);
  assert(t.elements.every(e => !e.classes.has('motion-pending')), 'Failure/reduced-motion must leave content visible');
}
{
  const t = setup();
  assert.equal(t.observed.size, 2, 'Above-fold/LCP content is not hidden');
  t.enter(t.elements[1]);
  assert(!t.observed.has(t.elements[1]), 'Reveal is one-shot');
  assert(t.elements[1].classes.has('motion-enter'));
  t.elements[1].listeners.animationend({ target: t.elements[0] });
  assert(t.elements[1].classes.has('motion-enter'), 'Ignore child animation events');
  t.reduce();
  assert(t.disconnected);
  assert(t.elements.every(e => e.classes.size === 0), 'Live preference change reveals all content');
}
{
  const t = setup({ hash: '#map' });
  assert(!t.elements[2].classes.has('motion-pending'), 'Anchor target is visible immediately');
  t.handlers.focusin({ target: t.elements[1] });
  assert(!t.elements[1].classes.has('motion-pending'), 'Keyboard focus is never hidden');
}
{
  const t = setup(); t.windowHandlers.pageshow({ persisted: true });
  assert(t.elements.every(e => e.classes.size === 0), 'Back/forward cache restores visible content');
}
console.log('Motion behavior: first paint, one-shot reveal, observer fallback, live reduced motion, anchors, focus and BFCache passed.');
// A thousand-row source still animates at most eight rendered cards.
for(const reduced of [false,true]){
 const t=setup({reduced});let animations=0;
 const context={document:{readyState:'loading',addEventListener(){}},window:{addEventListener(){}},matchMedia:()=>({matches:reduced,addEventListener(){}})};
 vm.runInNewContext(source,context);
 const cards=Array.from({length:1000},()=>({classList:{contains:()=>false},animate(){animations++}}));
 context.window.profisportMotion.catalog({children:cards});
 assert.equal(animations,reduced?0:8,'Catalog animation work must remain bounded');
}
