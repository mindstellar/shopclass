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
    var results = root.querySelector('[data-cod-results]'), timer = 0, ctl = null, rows = [], active = -1;

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
        box.value = ''; close(); box.focus();
    }
    function close() {
        results.hidden = true; results.replaceChildren(); rows = [];
        box.setAttribute('aria-expanded', 'false');
        mark(-1);
    }
    function mark(at) {
        var opts = results.querySelectorAll('[role="option"]');
        active = at;
        opts.forEach(function (o, i) { o.setAttribute('aria-selected', i === at ? 'true' : 'false'); });
        if (at >= 0 && opts[at]) {
            box.setAttribute('aria-activedescendant', opts[at].id);
            opts[at].scrollIntoView({ block: 'nearest' });
        } else {
            box.removeAttribute('aria-activedescendant');
        }
    }
    function show(data) {
        results.replaceChildren();
        rows = [];
        (data.countries || []).forEach(function (r) { rows.push({ type: 'country', id: r.code, name: r.name, path: '' }); });
        (data.regions || []).forEach(function (r) { rows.push({ type: 'region', id: String(r.id), name: r.name, path: r.country_name || '' }); });
        (data.cities || []).forEach(function (r) { rows.push({ type: 'city', id: String(r.id), name: r.name, path: [r.region_name, r.country_name].filter(Boolean).join(', ') }); });
        rows.forEach(function (r, i) {
            var li = el('li', 'cod-result');
            li.id = results.id + '-' + i;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.append(el('span', '', r.name), el('small', 'text-body-secondary', i18n[r.type] + (r.path ? ' \u00b7 ' + r.path : '')));
            // mousedown, so the search box keeps focus.
            li.addEventListener('mousedown', function (e) { e.preventDefault(); add(r); });
            results.append(li);
        });
        if (!rows.length) { results.append(el('li', 'cod-none', i18n.none)); }
        results.hidden = false;
        box.setAttribute('aria-expanded', 'true');
        mark(-1);
    }
    box.addEventListener('input', function () {
        clearTimeout(timer);
        if (ctl) { ctl.abort(); }
        var q = box.value.trim();
        if (q === '') { close(); return; }
        timer = setTimeout(function () {
            ctl = new AbortController();
            fetch(root.dataset.ajax + '&q=' + encodeURIComponent(q), { credentials: 'same-origin', signal: ctl.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); }).then(show).catch(function () {});
        }, 250);
    });
    box.addEventListener('keydown', function (e) {
        var open = !results.hidden && rows.length > 0;
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            if (!open) { return; }
            e.preventDefault();
            var step = e.key === 'ArrowDown' ? 1 : -1;
            mark(active < 0 ? (step > 0 ? 0 : rows.length - 1) : (active + step + rows.length) % rows.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (open && active >= 0) { add(rows[active]); }
        } else if (e.key === 'Escape' && !results.hidden) {
            e.preventDefault();
            close();
        }
    });
    box.addEventListener('blur', function () { if (!results.hidden) { close(); } });
    draw();
}());
