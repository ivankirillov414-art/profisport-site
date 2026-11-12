import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import vm from 'node:vm';

const require = createRequire(import.meta.url);
const helperPath = fileURLToPath(new URL('../public/site/pricing.js', import.meta.url));
const pricing = require(helperPath);

function freeze(value) {
  Object.freeze(value);
  Object.values(value).forEach((child) => {
    if (child && typeof child === 'object') freeze(child);
  });
  return value;
}

const catalog = freeze({
  version: 1,
  currency: 'RUB',
  admission: { weekday: { child: 790, adult: 890 }, weekend: { child: 890, adult: 990 } },
  activities: {
    master_classes: [{ id: 'mc399', price: 399 }, { id: 'mc499', price: 499 }, { id: 'mc599', price: 599 }],
    vr: { price: 499 }
  },
  combos: [
    { id: 'combo999', master_classes: 2, vr: 1, price: 999, includes_admission: null },
    { id: 'combo1499', master_classes: 3, vr: 1, price: 1499, includes_admission: null },
    { id: 'combo1999', master_classes: 3, vr: 3, price: 1999, includes_admission: null }
  ]
});
const event = freeze({ opening_date: '2026-11-24', closing_date: '2027-01-18' });
const quote = (input) => pricing.quote(catalog, event, input);
const validInput = { date: '2026-11-27', children: 1, adults: 1 };

test('exports the exact API for CommonJS and a standalone browser script', () => {
  assert.deepEqual(Object.keys(pricing).sort(), ['basket', 'createPlanner', 'formatRub', 'quote']);
  assert.equal(globalThis.KosmoportPricing, pricing);
  const context = vm.createContext({});
  vm.runInContext(readFileSync(helperPath, 'utf8'), context);
  assert.equal(typeof context.KosmoportPricing.quote, 'function');
  assert.equal(typeof context.KosmoportPricing.formatRub, 'function');
  assert.equal(typeof context.KosmoportPricing.basket, 'function');
  assert.equal(typeof context.KosmoportPricing.createPlanner, 'function');
  assert.equal(context.KosmoportPricing.quote(catalog, event, validInput).admission_total, 1680);
});

test('Friday to Saturday changes only admission unit prices and the total', () => {
  assert.deepEqual(quote(freeze({ date: '2026-11-27', children: 2, adults: 3 })), {
    ok: true,
    date: '2026-11-27',
    day_type: 'weekday',
    children: 2,
    adults: 3,
    guest_count: 5,
    unit_prices: { child: 790, adult: 890 },
    admission_total: 4250,
    currency: 'RUB',
    catalog_version: 1
  });
  assert.deepEqual(quote({ date: '2026-11-28', children: 2, adults: 3 }), {
    ok: true,
    date: '2026-11-28',
    day_type: 'weekend',
    children: 2,
    adults: 3,
    guest_count: 5,
    unit_prices: { child: 890, adult: 990 },
    admission_total: 4750,
    currency: 'RUB',
    catalog_version: 1
  });
  assert.equal(quote({ ...validInput, date: '2026-11-29' }).day_type, 'weekend');
});

test('event boundaries are inclusive and New Year uses its weekday rate', () => {
  assert.equal(quote({ ...validInput, date: event.opening_date }).ok, true);
  assert.equal(quote({ ...validInput, date: event.closing_date }).ok, true);
  assert.deepEqual(quote({ ...validInput, date: '2026-11-23' }), { ok: false, error: 'outside_event' });
  assert.deepEqual(quote({ ...validInput, date: '2027-01-19' }), { ok: false, error: 'outside_event' });
  assert.equal(quote({ ...validInput, date: '2027-01-01' }).day_type, 'weekday');
  assert.equal(quote({ ...validInput, date: '2027-01-01' }).admission_total, 1680);
});

test('dates must be real calendar dates with exact YYYY-MM-DD syntax', () => {
  for (const date of [undefined, null, 20261127, '', '2026-1-27', '2026-11-2', '2026-11-27T00:00:00Z', ' 2026-11-27', '2026-11-27 ', '2026-11-31', '2027-02-29', '2026-00-01', '2026-13-01', '2026-11-00', '0000-01-01']) {
    assert.deepEqual(quote({ ...validInput, date }), { ok: false, error: 'invalid_date' }, String(date));
  }
  assert.deepEqual(quote({ ...validInput, date: '2028-02-29' }), { ok: false, error: 'outside_event' });
  assert.deepEqual(pricing.quote(catalog, { opening_date: '2028-02-28', closing_date: '2028-03-01' }, { ...validInput, date: '2028-02-29' }).day_type, 'weekday');
  assert.deepEqual(quote(), { ok: false, error: 'invalid_date' });
});

test('strict quantities reject coercion, fractions, non-finite values and empty groups', () => {
  for (const value of [-1, 201, 0.5, NaN, Infinity, -Infinity, '1', '', null, undefined, true, false, {}, []]) {
    assert.deepEqual(quote({ ...validInput, children: value }), { ok: false, error: 'invalid_quantity' }, `children: ${String(value)}`);
    assert.deepEqual(quote({ ...validInput, adults: value }), { ok: false, error: 'invalid_quantity' }, `adults: ${String(value)}`);
  }
  for (const input of [{ children: 0, adults: 0 }, { children: 100, adults: 101 }, { children: 200, adults: 1 }]) {
    assert.deepEqual(quote({ ...validInput, ...input }), { ok: false, error: 'invalid_quantity' });
  }
});

test('one-sided groups, equal counts and the maximum total have exact finite totals', () => {
  for (const [children, adults, total] of [[1, 0, 790], [0, 1, 890], [2, 2, 3360], [200, 0, 158000], [0, 200, 178000], [100, 100, 168000]]) {
    const result = quote({ ...validInput, children, adults });
    assert.equal(result.ok, true);
    assert.equal(result.guest_count, children + adults);
    assert.equal(result.admission_total, total);
    assert.equal(Number.isFinite(result.admission_total), true);
  }
});

test('combo selections and activity prices never change admission', () => {
  const input = freeze({ ...validInput, combo: 'combo1999', combos: ['combo999'], master_classes: 3, vr: 3 });
  assert.deepEqual(quote(input), quote(validInput));
  assert.deepEqual(pricing.quote({ ...catalog, combos: [], activities: {} }, event, input), quote(validInput));
});

test('calendar calculations remain identical across timezones', () => {
  const script = `const p = require(${JSON.stringify(helperPath)}); process.stdout.write(JSON.stringify(['2026-11-27', '2026-11-28', '2027-01-18'].map(date => p.quote(${JSON.stringify(catalog)}, ${JSON.stringify(event)}, {date, children: 1, adults: 1}))));`;
  const outputs = ['UTC', 'Asia/Yekaterinburg', 'America/Los_Angeles', 'Pacific/Auckland'].map((TZ) => execFileSync(process.execPath, ['-e', script], { encoding: 'utf8', env: { ...process.env, TZ } }));
  outputs.forEach((output) => assert.equal(output, outputs[0]));
  assert.deepEqual(JSON.parse(outputs[0]).map(({ day_type }) => day_type), ['weekday', 'weekend', 'weekday']);
});

test('Russian RUB formatting retains decimals and has an Intl-free fallback', () => {
  const normalize = (value) => value.replace(/[\u00a0\u202f]/g, ' ');
  for (const api of [pricing, (() => {
    const context = vm.createContext({ Intl: undefined });
    vm.runInContext(readFileSync(helperPath, 'utf8'), context);
    return context.KosmoportPricing;
  })()]) {
    assert.equal(normalize(api.formatRub(1234.5)), '1 234,5 ₽');
    assert.equal(normalize(api.formatRub(0)), '0 ₽');
    assert.equal(normalize(api.formatRub(100)), '100 ₽');
    assert.equal(normalize(api.formatRub(999.99)), '999,99 ₽');
    assert.equal(normalize(api.formatRub(-1234.5)), '-1 234,5 ₽');
    assert.equal(api.formatRub(NaN), '');
    assert.equal(api.formatRub(Infinity), '');
    assert.equal(api.formatRub('100'), '');
  }
});

test('basket has the exact line and total contract with no preselected purchases', () => {
  assert.deepEqual(pricing.basket(catalog, event, validInput), {
    ok: true,
    admission: quote(validInput),
    lines: [
      { id: 'child', label: 'Детский входной билет', quantity: 1, unit_price: 790, total: 790, kind: 'admission' },
      { id: 'adult', label: 'Взрослый входной билет', quantity: 1, unit_price: 890, total: 890, kind: 'admission' }
    ],
    admission_total: 1680,
    bundles_total: 0,
    extras_total: 0,
    planned_total: 1680,
    currency: 'RUB',
    requires_terms_confirmation: false,
    payable_total: null
  });
});

test('bundles are priced once and explicit extra activities remain separate purchases', () => {
  const result = pricing.basket(catalog, event, freeze({
    ...validInput,
    combos: { combo999: 2, combo1499: 1, combo1999: 1 },
    extras: { mc399: 1, mc499: 2, mc599: 1, vr: 3 }
  }));
  assert.equal(result.ok, true);
  assert.equal(result.admission_total, 1680);
  assert.equal(result.bundles_total, 2 * 999 + 1499 + 1999);
  assert.equal(result.extras_total, 399 + 2 * 499 + 599 + 3 * 499);
  assert.equal(result.planned_total, 10669);
  assert.equal(result.payable_total, null);
  assert.equal(result.requires_terms_confirmation, true);
  assert.deepEqual(result.lines.map(({ id }) => id), ['child', 'adult', 'combo999', 'combo1499', 'combo1999', 'mc399', 'mc499', 'mc599', 'vr']);
  assert.deepEqual(result.lines[2], { id: 'combo999', label: '2 мастер-класса + 1 VR', quantity: 2, unit_price: 999, total: 1998, kind: 'combo' });
  assert.deepEqual(result.lines[8], { id: 'vr', label: 'VR', quantity: 3, unit_price: 499, total: 1497, kind: 'extra' });
  const bundleOnly = pricing.basket(catalog, event, { ...validInput, combos: { combo999: 1 } });
  assert.equal(bundleOnly.planned_total, 2679);
  assert.equal(bundleOnly.extras_total, 0);
  assert.deepEqual(bundleOnly.lines.map(({ id }) => id), ['child', 'adult', 'combo999']);
});

test('zero quantities omit lines and only unknown bundle admission terms need confirmation', () => {
  const result = pricing.basket(catalog, event, { ...validInput, children: 0, combos: { combo999: 0 }, extras: { vr: 1 } });
  assert.deepEqual(result.lines.map(({ id }) => id), ['adult', 'vr']);
  assert.equal(result.requires_terms_confirmation, false);
  const confirmedCatalog = { ...catalog, combos: catalog.combos.map((item) => ({ ...item, includes_admission: false })) };
  assert.equal(pricing.basket(confirmedCatalog, event, { ...validInput, combos: { combo999: 1 } }).requires_terms_confirmation, false);
});

test('basket rejects unknown IDs, malformed maps and non-integer quantities', () => {
  for (const kind of ['combos', 'extras']) {
    const id = kind === 'combos' ? 'combo999' : 'vr';
    for (const value of [-1, 21, 0.5, NaN, Infinity, -Infinity, '1', '', null, undefined, true, false, {}, []]) {
      assert.deepEqual(pricing.basket(catalog, event, { ...validInput, [kind]: { [id]: value } }), { ok: false, error: 'invalid_selection' });
    }
    for (const map of [null, [], [1], 1, '1', true, new Date(), { unknown: 1 }, { [id]: 1, unknown: 0 }]) {
      assert.deepEqual(pricing.basket(catalog, event, { ...validInput, [kind]: map }), { ok: false, error: 'invalid_selection' });
    }
    assert.equal(pricing.basket(catalog, event, { ...validInput, [kind]: { [id]: 20 } }).ok, true);
  }
  assert.deepEqual(pricing.basket(catalog, event, { ...validInput, date: 'bad', extras: { unknown: 1 } }), { ok: false, error: 'invalid_date' });
  assert.deepEqual(pricing.basket(catalog, event, { ...validInput, children: 0, adults: 0, extras: { unknown: 1 } }), { ok: false, error: 'invalid_quantity' });
});

test('planner starts with opening-date admission and zero optional purchases', () => {
  const planner = pricing.createPlanner(catalog, event);
  assert.deepEqual(planner.getState(), {
    step: 1,
    date: event.opening_date,
    children: 1,
    adults: 1,
    combos: { combo999: 0, combo1499: 0, combo1999: 0 },
    extras: { mc399: 0, mc499: 0, mc599: 0, vr: 0 }
  });
  assert.equal(planner.summary().planned_total, 1680);
  assert.equal(planner.summary().bundles_total, 0);
  assert.equal(planner.summary().extras_total, 0);
  assert.equal(planner.back(), false);
  assert.equal(planner.next().step, 2);
});

test('planner navigation preserves selections and every returned state is detached', () => {
  const initial = { ...validInput, combos: { combo999: 1 }, extras: { vr: 2 } };
  const planner = pricing.createPlanner(catalog, event, initial);
  initial.combos.combo999 = 10;
  initial.extras.vr = 10;
  assert.equal(planner.getState().combos.combo999, 1);
  const changed = planner.setQuantity('extras', 'mc399', 3);
  changed.extras.mc399 = 20;
  const state = planner.getState();
  state.combos.combo999 = 20;
  state.date = 'bad';
  planner.goToStep(4);
  const summary = planner.summary();
  summary.lines[0].quantity = 200;
  summary.admission.unit_prices.child = 1;
  assert.equal(planner.back().step, 3);
  assert.equal(planner.back().step, 2);
  assert.equal(planner.back().step, 1);
  assert.deepEqual(planner.getState(), {
    step: 1,
    ...validInput,
    combos: { combo999: 1, combo1499: 0, combo1999: 0 },
    extras: { mc399: 3, mc499: 0, mc599: 0, vr: 2 }
  });
  assert.equal(planner.summary().admission.unit_prices.child, 790);
  assert.equal(planner.summary().lines[0].quantity, 1);
});

test('Friday to Saturday changes admission only and retains planner choices', () => {
  const planner = pricing.createPlanner(catalog, event, validInput);
  planner.setQuantity('combos', 'combo1999', 2);
  planner.setQuantity('extras', 'vr', 3);
  planner.goToStep(3);
  const friday = planner.summary();
  assert.equal(planner.setDate('2026-11-28').step, 3);
  const saturday = planner.summary();
  assert.equal(saturday.admission_total - friday.admission_total, 200);
  assert.equal(saturday.planned_total - friday.planned_total, 200);
  assert.equal(saturday.bundles_total, friday.bundles_total);
  assert.equal(saturday.extras_total, friday.extras_total);
  assert.deepEqual(saturday.lines.filter(({ kind }) => kind !== 'admission'), friday.lines.filter(({ kind }) => kind !== 'admission'));
});

test('planner refuses invalid quantities, IDs and navigation without losing choices', () => {
  const planner = pricing.createPlanner(catalog, event);
  const before = planner.getState();
  for (const args of [['combos', 'bad', 1], ['extras', 'bad', 0], ['extras', ['vr'], 1], ['extras', { toString: () => 'vr' }, 1], ['combo', 'combo999', 1], ['extras', 'vr', '1'], ['extras', 'vr', NaN], ['extras', 'vr', 0.5], ['extras', 'vr', -1], ['combos', 'combo999', 21]]) {
    assert.equal(planner.setQuantity(...args), false);
    assert.deepEqual(planner.getState(), before);
  }
  for (const step of [0, 5, 1.5, NaN, '2', null]) {
    assert.equal(planner.goToStep(step), false);
    assert.deepEqual(planner.getState(), before);
  }
  assert.equal(planner.setDate({}), false);
  assert.equal(planner.setGuests('1', 1), false);
  assert.deepEqual(planner.getState(), before);
  planner.setQuantity('combos', 'combo999', 1);
  planner.goToStep(4);
  assert.equal(planner.next(), false);
  assert.equal(planner.setDate('2026-11-31').step, 1);
  assert.deepEqual(planner.summary(), { ok: false, error: 'invalid_date' });
  assert.equal(planner.next(), false);
  assert.equal(planner.goToStep(4), false);
  planner.setDate('2026-11-27');
  assert.equal(planner.setGuests(0, 0).step, 1);
  assert.deepEqual(planner.summary(), { ok: false, error: 'invalid_quantity' });
  assert.equal(planner.goToStep(2), false);
  planner.setGuests(2, 1);
  assert.equal(planner.next().step, 2);
  assert.equal(planner.getState().combos.combo999, 1);
});

test('invalid initial admission cannot start or move beyond step one', () => {
  const planner = pricing.createPlanner(catalog, event, { ...validInput, date: '2026-11-23', step: 4 });
  assert.equal(planner.getState().step, 1);
  assert.equal(planner.next(), false);
  assert.equal(planner.goToStep(3), false);
  assert.deepEqual(planner.summary(), { ok: false, error: 'outside_event' });
});
