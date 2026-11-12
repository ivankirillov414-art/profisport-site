(function (root, factory) {
  'use strict';

  var pricing = factory();
  root.KosmoportPricing = pricing;

  if (typeof module === 'object' && module.exports) {
    module.exports = pricing;
  }
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  'use strict';

  function parseDate(value) {
    if (typeof value !== 'string' || value.length !== 10 || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
      return null;
    }

    var year = Number(value.slice(0, 4));
    var month = Number(value.slice(5, 7));
    var day = Number(value.slice(8, 10));
    if (year < 1 || month < 1 || month > 12 || day < 1 || day > 31) {
      return null;
    }

    // setUTCFullYear also handles years 0001..0099 without Date.UTC's 1900 offset.
    var date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    date.setUTCHours(0, 0, 0, 0);

    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) {
      return null;
    }

    return date;
  }

  function validQuantity(value) {
    return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 200;
  }

  function quote(catalog, event, input) {
    var date = parseDate(input && input.date);
    if (!date) {
      return { ok: false, error: 'invalid_date' };
    }

    var openingDate = parseDate(event && event.opening_date);
    var closingDate = parseDate(event && event.closing_date);
    if (!openingDate || !closingDate || date.getTime() < openingDate.getTime() || date.getTime() > closingDate.getTime()) {
      return { ok: false, error: 'outside_event' };
    }

    var children = input.children;
    var adults = input.adults;
    if (!validQuantity(children) || !validQuantity(adults) || children + adults < 1 || children + adults > 200) {
      return { ok: false, error: 'invalid_quantity' };
    }

    var day = date.getUTCDay();
    var dayType = day === 0 || day === 6 ? 'weekend' : 'weekday';
    var prices = catalog.admission[dayType];

    // Combos and activities are separate purchases and never enter admission.
    return {
      ok: true,
      date: input.date,
      day_type: dayType,
      children: children,
      adults: adults,
      guest_count: children + adults,
      unit_prices: { child: prices.child, adult: prices.adult },
      admission_total: children * prices.child + adults * prices.adult,
      currency: catalog.currency,
      catalog_version: catalog.version
    };
  }

  function formatRub(number) {
    if (typeof number !== 'number' || !Number.isFinite(number)) {
      return '';
    }

    if (typeof Intl !== 'undefined' && typeof Intl.NumberFormat === 'function') {
      return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
      }).format(number);
    }

    var rounded = Math.round(Math.abs(number) * 100) / 100;
    var parts = rounded.toFixed(2).replace(/\.?0+$/, '').split('.');
    var integer = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '\u00a0');
    return (number < 0 ? '-' : '') + integer + (parts[1] ? ',' + parts[1] : '') + '\u00a0\u20bd';
  }

  var labels = {
    child: '\u0414\u0435\u0442\u0441\u043a\u0438\u0439 \u0432\u0445\u043e\u0434\u043d\u043e\u0439 \u0431\u0438\u043b\u0435\u0442',
    adult: '\u0412\u0437\u0440\u043e\u0441\u043b\u044b\u0439 \u0432\u0445\u043e\u0434\u043d\u043e\u0439 \u0431\u0438\u043b\u0435\u0442',
    combo999: '2 \u043c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441\u0430 + 1 VR',
    combo1499: '3 \u043c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441\u0430 + 1 VR',
    combo1999: '3 \u043c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441\u0430 + 3 VR',
    mc399: '\u041c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441 399 \u20bd',
    mc499: '\u041c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441 499 \u20bd',
    mc599: '\u041c\u0430\u0441\u0442\u0435\u0440-\u043a\u043b\u0430\u0441\u0441 599 \u20bd',
    vr: 'VR'
  };

  function own(object, key) {
    return Object.prototype.hasOwnProperty.call(object, key);
  }

  function selections(catalog, kind) {
    return kind === 'combos' ? catalog.combos : catalog.activities.master_classes.concat([{ id: 'vr', price: catalog.activities.vr.price }]);
  }

  function selectionMap(catalog, kind, value) {
    var items = selections(catalog, kind);
    var result = {};
    items.forEach(function (item) { result[item.id] = 0; });
    if (typeof value === 'undefined') {
      return result;
    }
    if (!value || Object.prototype.toString.call(value) !== '[object Object]') {
      return false;
    }
    var keys = Object.keys(value);
    for (var i = 0; i < keys.length; i += 1) {
      var id = keys[i];
      var quantity = value[id];
      if (!own(result, id) || typeof quantity !== 'number' || !Number.isInteger(quantity) || quantity < 0 || quantity > 20) {
        return false;
      }
      result[id] = quantity;
    }
    return result;
  }

  function basket(catalog, event, input) {
    var admission = quote(catalog, event, input);
    if (!admission.ok) {
      return admission;
    }
    var combos = selectionMap(catalog, 'combos', input.combos);
    var extras = selectionMap(catalog, 'extras', input.extras);
    if (!combos || !extras) {
      return { ok: false, error: 'invalid_selection' };
    }

    var lines = [];
    var bundlesTotal = 0;
    var extrasTotal = 0;
    var requiresTermsConfirmation = false;
    function line(id, quantity, price, kind) {
      if (quantity > 0) {
        lines.push({ id: id, label: labels[id], quantity: quantity, unit_price: price, total: quantity * price, kind: kind });
      }
    }
    line('child', admission.children, admission.unit_prices.child, 'admission');
    line('adult', admission.adults, admission.unit_prices.adult, 'admission');
    selections(catalog, 'combos').forEach(function (item) {
      var quantity = combos[item.id];
      line(item.id, quantity, item.price, 'combo');
      bundlesTotal += quantity * item.price;
      if (quantity > 0 && item.includes_admission === null) {
        requiresTermsConfirmation = true;
      }
    });
    selections(catalog, 'extras').forEach(function (item) {
      var quantity = extras[item.id];
      line(item.id, quantity, item.price, 'extra');
      extrasTotal += quantity * item.price;
    });
    return {
      ok: true,
      admission: admission,
      lines: lines,
      admission_total: admission.admission_total,
      bundles_total: bundlesTotal,
      extras_total: extrasTotal,
      planned_total: admission.admission_total + bundlesTotal + extrasTotal,
      currency: catalog.currency,
      requires_terms_confirmation: requiresTermsConfirmation,
      payable_total: null
    };
  }

  /**
   * A local four-step planner. Getters and successful setters return detached state
   * copies. Invalid dates/guest values stay editable at step 1; invalid selection
   * setters/navigation return false without changing the existing choices.
   * initial may supply date, children, adults, combos, extras and step; malformed
   * initial selection maps use empty selections. summary() returns basket().
   */
  function createPlanner(catalog, event, initial) {
    initial = initial || {};
    var state = {
      step: 1,
      date: own(initial, 'date') ? (typeof initial.date === 'string' ? initial.date : null) : event.opening_date,
      children: own(initial, 'children') ? (typeof initial.children === 'number' ? initial.children : null) : 1,
      adults: own(initial, 'adults') ? (typeof initial.adults === 'number' ? initial.adults : null) : 1,
      combos: selectionMap(catalog, 'combos', initial.combos) || selectionMap(catalog, 'combos'),
      extras: selectionMap(catalog, 'extras', initial.extras) || selectionMap(catalog, 'extras')
    };

    function copy() {
      return {
        step: state.step,
        date: state.date,
        children: state.children,
        adults: state.adults,
        combos: Object.assign({}, state.combos),
        extras: Object.assign({}, state.extras)
      };
    }

    function checkAdmission() {
      if (!quote(catalog, event, state).ok) {
        state.step = 1;
      }
      return copy();
    }

    function goToStep(step) {
      if (!Number.isInteger(step) || step < 1 || step > 4 || (step > 1 && !quote(catalog, event, state).ok)) {
        return false;
      }
      state.step = step;
      return copy();
    }

    if (own(initial, 'step')) {
      goToStep(initial.step);
    }

    return {
      getState: copy,
      setDate: function (date) {
        if (typeof date !== 'string') { return false; }
        state.date = date;
        return checkAdmission();
      },
      setGuests: function (children, adults) {
        if (typeof children !== 'number' || typeof adults !== 'number') { return false; }
        state.children = children;
        state.adults = adults;
        return checkAdmission();
      },
      setQuantity: function (kind, id, quantity) {
        if ((kind !== 'combos' && kind !== 'extras') || typeof id !== 'string' || !own(state[kind], id) || typeof quantity !== 'number' || !Number.isInteger(quantity) || quantity < 0 || quantity > 20) {
          return false;
        }
        state[kind][id] = quantity;
        return copy();
      },
      goToStep: goToStep,
      next: function () { return goToStep(state.step + 1); },
      back: function () { return goToStep(state.step - 1); },
      summary: function () { return basket(catalog, event, state); }
    };
  }

  return { quote: quote, formatRub: formatRub, basket: basket, createPlanner: createPlanner };
}));
