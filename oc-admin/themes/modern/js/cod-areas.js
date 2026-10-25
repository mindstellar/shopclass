/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Settings > Billing > Shop: the cash on delivery areas. Chips for the chosen places, and a
 * search over the locations that adds one; the hidden field holds the list as JSON.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-cod-areas]');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var i18n = JSON.parse(root.dataset.i18n), chips = JSON.parse(root.dataset.chips);
    var store = document.querySelector('input[name="' + root.dataset.store + '"]');
    var list = root.querySelector('[data-cod-chips]'), box = root.querySelector('[data-cod-search]');
    var results = root.querySelector('[data-cod-results]'), timer = 0, ctl = null;

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text) { n.textContent = text; }
        return n;
    }
    function save() {
        store.value = chips.length ? JSON.stringify(chips.map(function (c) { return { type: c.type, id: c.id }; })) : '';
    }
    function draw() {
        list.replaceChildren();
        chips.forEach(function (c, at) {
            var li = el('li', 'cod-chip'), b = el('button', 'cod-chip-x', '\u00d7');
            li.append(el('span', '', c.name + (c.path ? ' (' + c.path + ')' : '')));
            b.type = 'button';
            b.setAttribute('aria-label', i18n.remove.replace('%s', c.name));
            b.addEventListener('click', function () { chips.splice(at, 1); save(); draw(); box.focus(); });
            li.append(b);
            list.append(li);
        });
    }
    function add(c) {
        if (!chips.some(function (x) { return x.type === c.type && String(x.id) === String(c.id); })) {
            chips.push(c); save(); draw();
        }
        box.value = ''; results.hidden = true; results.replaceChildren(); box.focus();
    }
    function show(data) {
        results.replaceChildren();
        var rows = [];
        (data.countries || []).forEach(function (r) { rows.push({ type: 'country', id: r.code, name: r.name, path: '' }); });
        (data.regions || []).forEach(function (r) { rows.push({ type: 'region', id: String(r.id), name: r.name, path: r.country_name || '' }); });
        (data.cities || []).forEach(function (r) { rows.push({ type: 'city', id: String(r.id), name: r.name, path: [r.region_name, r.country_name].filter(Boolean).join(', ') }); });
        rows.forEach(function (r) {
            var li = el('li'), b = el('button', 'cod-result');
            b.type = 'button';
            b.append(el('span', '', r.name), el('small', 'text-body-secondary', i18n[r.type] + (r.path ? ' \u00b7 ' + r.path : '')));
            b.addEventListener('click', function () { add(r); });
            li.append(b);
            results.append(li);
        });
        if (!rows.length) { results.append(el('li', 'cod-none', i18n.none)); }
        results.hidden = false;
    }
    box.addEventListener('input', function () {
        clearTimeout(timer);
        if (ctl) { ctl.abort(); }
        var q = box.value.trim();
        if (q === '') { results.hidden = true; return; }
        timer = setTimeout(function () {
            ctl = new AbortController();
            fetch(root.dataset.ajax + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); }).then(show).catch(function () {});
        }, 250);
    });
    box.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); }
        if (e.key === 'Escape') { results.hidden = true; }
    });
    draw();
}());
