/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The checkout address picker. A saved address fills the form's fields of the same name and hides
 * the [data-address-form] box; "Use a new address" shows it empty. Each change fires osc:address.
 */
(function () {
  'use strict';

  var FIELDS = ['name', 'phone', 'line1', 'city', 'state', 'postcode', 'country'];

  function init(picker) {
    var form = picker.closest('form') || document;
    var box = form.querySelector('[data-address-form]');
    var save = form.querySelector('[data-address-save]');
    var picked = '';

    function chosen() {
      var radio = picker.querySelector('input[type=radio]:checked');
      if (!radio || !radio.value) return { id: '', address: null };
      try {
        return { id: radio.value, address: JSON.parse(radio.getAttribute('data-address') || 'null') };
      } catch (e) {
        return { id: '', address: null };
      }
    }

    function field(name) {
      var f = form.querySelector('[name="' + name + '"]');
      return f && f.closest('[data-address-picker]') === null ? f : null;
    }

    function apply(first) {
      var now = chosen();
      FIELDS.forEach(function (name) {
        var f = field(name);
        if (!f) return;
        if (now.address) f.value = String(now.address[name] || '');
        else if (!first && picked && name !== 'country') f.value = '';
      });
      if (box) box.hidden = !!now.address;
      if (save) save.hidden = !!now.address;
      picked = now.id;
      picker.dispatchEvent(new CustomEvent('osc:address', { bubbles: true, detail: now }));
      if (!now.address && !first) {
        var input = field('name');
        if (input) input.focus();
      }
    }

    picker.addEventListener('change', function (e) {
      if (e.target && e.target.type === 'radio') apply(false);
    });
    apply(true);
  }

  function start() {
    document.querySelectorAll('[data-address-picker]').forEach(init);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
