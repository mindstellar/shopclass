/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Reshapes an address form to the chosen country: field order, hidden fields, labels, required
 * marks and the postcode pattern, and offers the shop's regions as the State list. The formats come
 * from #osc-address-formats; the server still checks every address. A theme can listen for
 * `osc:address-country` on the form (detail: country, format, regions) to build its own widget.
 */
(function () {
  'use strict';

  var holder = document.getElementById('osc-address-formats');
  if (!holder) return;
  var formats = {};
  try {
    formats = JSON.parse(holder.textContent || '{}') || {};
  } catch (e) {
    return;
  }
  var NEEDED = holder.getAttribute('data-required') || '';
  var BAD = holder.getAttribute('data-pattern') || '';
  var REGIONS = holder.getAttribute('data-regions-url') || '';
  var CHOOSE = holder.getAttribute('data-choose') || '';
  var loaded = {};

  function regionsOf(code) {
    if (!REGIONS || !code || typeof fetch !== 'function') return Promise.resolve([]);
    if (!loaded[code]) {
      loaded[code] = fetch(REGIONS + encodeURIComponent(code), { credentials: 'same-origin' })
        .then(function (res) {
          return res.ok ? res.json() : [];
        })
        .then(function (rows) {
          var names = [];
          (Array.isArray(rows) ? rows : []).forEach(function (row) {
            var name = row && row.s_name ? String(row.s_name) : '';
            if (name && names.indexOf(name) === -1) names.push(name);
          });
          return names;
        })
        .catch(function () {
          return [];
        });
    }
    return loaded[code];
  }

  function init(form) {
    var country = form.querySelector('[name="country"]');
    if (!country) return;

    function wrap(name) {
      return form.querySelector('[data-address-field="' + name + '"]');
    }

    function input(name) {
      var box = wrap(name);
      return box ? box.querySelector('[name="' + name + '"]') : null;
    }

    function relabel(name, text) {
      var box = wrap(name);
      var label = box && box.querySelector('label');
      if (label && text) label.textContent = text;
    }

    function clear(field) {
      field.removeAttribute('aria-invalid');
      field.setCustomValidity('');
    }

    function option(value, text) {
      var o = document.createElement('option');
      o.value = value;
      o.textContent = text;
      return o;
    }

    function setState(names, keep) {
      var field = input('state');
      if (!field) return;
      var value = field.value;
      var list = names.length > 0;
      var next = field;
      if (list !== (field.tagName === 'SELECT')) {
        next = document.createElement(list ? 'select' : 'input');
        ['id', 'name', 'class', 'autocomplete', 'aria-invalid', 'aria-describedby'].forEach(function (attr) {
          var v = field.getAttribute(attr);
          if (v !== null && v !== undefined) next.setAttribute(attr, v);
        });
        if (!list) {
          next.setAttribute('type', 'text');
          next.setAttribute('maxlength', '100');
          next.value = keep ? value : '';
        }
        next.required = field.required;
        next.disabled = field.disabled;
        field.parentNode.replaceChild(next, field);
      }
      if (!list) return;
      while (next.firstChild) next.removeChild(next.firstChild);
      next.appendChild(option('', CHOOSE));
      names.forEach(function (name) {
        next.appendChild(option(name, name));
      });
      if (value && names.indexOf(value) === -1 && keep) next.appendChild(option(value, value));
      next.value = value && (keep || names.indexOf(value) !== -1) ? value : '';
    }

    var pending = 0;

    function announce(first) {
      var code = country.value;
      var format = formats[code] || null;
      var ask = format && (format.fields || []).indexOf('state') !== -1;
      var mine = ++pending;
      (ask ? regionsOf(code) : Promise.resolve([])).then(function (regions) {
        if (mine !== pending) return;
        if (ask) setState(regions, first === true);
        form.dispatchEvent(new CustomEvent('osc:address-country', {
          bubbles: true,
          detail: { country: code, format: format, regions: regions },
        }));
      });
    }

    function apply(first) {
      var format = formats[country.value];
      if (!format) return;
      var box = form.querySelector('[data-address-fields]');
      var names = ['line1', 'line2', 'city', 'state', 'postcode'];
      var shown = format.fields || [];
      if (box) {
        shown.forEach(function (name) {
          var el = wrap(name);
          if (el && el.parentNode === box) box.appendChild(el);
        });
      }
      names.forEach(function (name) {
        var el = wrap(name);
        var field = input(name);
        if (!el || !field) return;
        var on = shown.indexOf(name) !== -1;
        el.hidden = !on;
        field.disabled = !on;
        field.required = on && (format.required || []).indexOf(name) !== -1;
        if (first !== true) clear(field);
      });
      relabel('state', format.state);
      relabel('postcode', format.postcode);
      var post = input('postcode');
      if (post && format.example) post.placeholder = format.example;
      else if (post) post.removeAttribute('placeholder');
    }

    function postcodeProblem() {
      var format = formats[country.value];
      var post = input('postcode');
      if (!format || !post || post.disabled || !format.pattern || post.value.trim() === '') return '';
      var ok = true;
      try {
        ok = new RegExp(format.pattern, 'i').test(post.value.trim());
      } catch (e) {
        return '';
      }
      return ok ? '' : BAD.replace('%1$s', String(format.postcode || '').toLowerCase()).replace('%2$s', format.example || '');
    }

    function mark(field, message) {
      field.setCustomValidity(message);
      if (message) field.setAttribute('aria-invalid', 'true');
      else field.removeAttribute('aria-invalid');
    }

    country.addEventListener('change', function () {
      apply(false);
      announce(false);
    });
    var post = input('postcode');
    if (post) {
      // Marks the field only: reporting here would pull focus back while the buyer moves on.
      post.addEventListener('change', function () {
        mark(post, postcodeProblem());
      });
    }
    form.addEventListener('submit', function (event) {
      var bad = null;
      Array.prototype.forEach.call(form.querySelectorAll('[data-address-field] [name]'), function (field) {
        if (field.disabled) return;
        var message = '';
        if (field.name === 'postcode') message = postcodeProblem();
        if (!message && field.required && field.value.trim() === '') message = NEEDED;
        mark(field, message);
        if (message && !bad) bad = field;
      });
      if (bad) {
        event.preventDefault();
        bad.reportValidity();
      }
    });
    apply(true);
    announce(true);
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-address-form]'), init);
})();
