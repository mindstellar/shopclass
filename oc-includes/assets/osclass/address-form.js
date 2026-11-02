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
 * marks and the postcode pattern. The formats come from #osc-address-formats; the server still
 * checks every address.
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
    });
    var post = input('postcode');
    if (post) {
      post.addEventListener('change', function () {
        mark(post, postcodeProblem());
        if (post.validationMessage) post.reportValidity();
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
  }

  Array.prototype.forEach.call(document.querySelectorAll('[data-address-form]'), init);
})();
