/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The place field: an oscAutocomplete() input that picks a city, with its region and country, from GET /cities.
 * It binds every [data-osc-place] block, and data-strict="1" refuses a submit of text not picked from the list.
 */
(function () {
    'use strict';

    function fold(s) {
        return String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }

    function fill(text, value) {
        return text.replace(/%[sd]/, function () { return String(value); });
    }

    function apiMeta() {
        var meta = document.querySelector('meta[name="shopclass-api"]');
        try {
            return meta ? JSON.parse(meta.getAttribute('content')) : null;
        } catch (e) {
            return null;
        }
    }

    function el(tag, cls, text) {
        var node = document.createElement(tag);
        if (cls) { node.className = cls; }
        if (text !== undefined) { node.textContent = text; }
        return node;
    }

    // The nodes given, without the icons the page did not ship.
    function nodes() {
        return Array.prototype.filter.call(arguments, Boolean);
    }

    function oscPlaceField(root) {
        if (!root || root.getAttribute('data-osc-place-ready') || typeof oscAutocomplete !== 'function') { return; }
        var input = root.querySelector('.oe-place__input');
        var list = root.querySelector('.osc-ac-list');
        var api = apiMeta();
        if (!input || !list) { return; }
        root.setAttribute('data-osc-place-ready', '1');

        var strict = root.getAttribute('data-strict') === '1';
        var multi = root.getAttribute('data-countries') === 'n';
        var min = parseInt(root.getAttribute('data-min'), 10) || 2;
        var limit = Math.min(20, Math.max(1, parseInt(root.getAttribute('data-limit'), 10) || 8));
        var endpoint = (api && api.url ? api.url : '').replace(/\/$/, '') + '/cities';
        var joiner = endpoint.indexOf('?') > -1 ? '&' : '?';
        var uid = input.id || 'place';

        var spin = root.querySelector('.oe-place__spin');
        var clear = root.querySelector('.oe-place__clear');
        var note = root.querySelector('.oe-place__note');
        var err = root.querySelector('.oe-field-error');
        var live = root.querySelector('.oe-place__live');
        var savedBtn = root.querySelector('.oe-place__saved');
        var icons = root.querySelector('template.oe-place__icons');
        var form = input.form || (root.closest ? root.closest('form') : null);
        var ids = {
            city: root.querySelector('input[name="cityId"]'),
            region: root.querySelector('input[name="regionId"]'),
            country: root.querySelector('input[name="countryId"]')
        };
        var typed = [root.querySelector('input[name="region"]'), root.querySelector('input[name="country"]')];
        var errorId = err && err.id ? err.id : '';
        var described = (input.getAttribute('aria-describedby') || '').split(/\s+/)
            .filter(function (id) { return id && id !== errorId; }).join(' ');

        var picked = null;
        var origin = '';
        var cache = {};
        var saved = null;
        var ac = null;

        function text(key) {
            return root.getAttribute('data-text-' + key) || '';
        }

        function icon(name) {
            var svg = icons && icons.content ? icons.content.querySelector('[data-icon="' + name + '"]') : null;
            return svg ? svg.cloneNode(true) : null;
        }

        function say(msg) {
            if (live) { live.textContent = msg; }
        }

        function syncClear() {
            if (clear) { clear.hidden = !input.value || (spin && !spin.hidden); }
        }

        function setBusy(on) {
            if (spin) { spin.hidden = !on; }
            syncClear();
        }

        function statusRow(msg, cls) {
            var li = el('li', 'oe-place__status' + (cls ? ' ' + cls : ''), msg);
            li.setAttribute('role', 'presentation');
            return li;
        }

        function showError(msg) {
            if (!err) { return; }
            err.textContent = msg;
            err.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', (errorId ? errorId + ' ' : '') + described);
        }

        function hideError() {
            if (err) { err.hidden = true; }
            input.removeAttribute('aria-invalid');
            input.setAttribute('aria-describedby', described);
        }

        function sync() {
            root.classList.toggle('is-picked', !!picked);
            if (note) {
                if (picked) {
                    note.className = 'oe-place__note oe-place__note--picked';
                    note.replaceChildren.apply(note, nodes(icon('tick'), el('span', '', text('note-' + (origin || 'list')))));
                    note.hidden = false;
                } else if (!strict && input.value.trim() && list.hidden) {
                    note.className = 'oe-place__note';
                    note.replaceChildren(el('span', '', text('note-typed')));
                    note.hidden = false;
                } else {
                    note.replaceChildren();
                    note.hidden = true;
                }
            }
            if (savedBtn) {
                savedBtn.hidden = !saved || !!(picked && String(picked.id) === String(saved.city_id));
            }
            syncClear();
        }

        function setIds(c) {
            ids.city.value = c ? c.id : '';
            ids.region.value = c ? c.region_id : '';
            ids.country.value = c ? c.country_code : '';
            typed.forEach(function (t) { if (t) { t.value = ''; } });
        }

        // Returns false so oscAutocomplete leaves the input to this field.
        function pick(c, from) {
            picked = c;
            origin = from || 'list';
            input.value = c ? c.label : '';
            setIds(c);
            hideError();
            ac.close();
            if (c && origin === 'list') { say(fill(text('live-chosen'), c.label)); }
            return false;
        }

        function unpick() {
            picked = null;
            origin = '';
            setIds(null);
        }

        function optionRow(c, li, i, q) {
            var sel = !!(picked && String(picked.id) === String(c.id));
            var label = el('span');
            var name = el('span', 'oe-place__name');
            var n = fold(q).length;
            li.id = uid + '-opt-' + i;
            li.setAttribute('aria-selected', sel ? 'true' : 'false');
            if (fold(c.name).indexOf(fold(q)) === 0) {
                name.appendChild(el('b', '', c.name.slice(0, n)));
                name.appendChild(document.createTextNode(c.name.slice(n)));
            } else {
                name.appendChild(el('b', '', c.name));
            }
            label.appendChild(name);
            if (c.region) { label.appendChild(el('span', 'oe-place__region', ', ' + c.region)); }
            li.appendChild(label);
            if (multi && c.country_code) {
                var cc = el('span', 'oe-place__cc', c.country_code);
                cc.setAttribute('title', c.country || c.country_code);
                li.appendChild(cc);
            }
            if (sel) {
                var tick = el('span', 'oe-place__tick');
                tick.replaceChildren.apply(tick, nodes(icon('tick')));
                li.appendChild(tick);
            }
        }

        function footer(q, rows, more) {
            if (!rows.length) {
                return [statusRow(fill(text(strict ? 'none-on' : 'none-off'), q.trim()))];
            }
            // The server stops counting at 100, so a count at the cap reads "100+".
            return more > 0 ? [statusRow(fill(text('more'), more >= 100 ? '100+' : more), 'oe-place__status--more')] : [];
        }

        function failure() {
            var li = statusRow('', 'oe-place__status--error');
            var retry = el('button', 'oe-place__retry', text('retry'));
            li.replaceChildren.apply(li, nodes(icon('alert'), el('span', '', text(strict ? 'error' : 'error-off')), retry));
            retry.setAttribute('type', 'button');
            retry.addEventListener('click', function () {
                input.focus();
                ac.search();
            });
            setBusy(false);
            say(text('error'));
            return [li];
        }

        // A shorter prefix whose complete answer matched by displayed name holds every match of a longer one.
        function narrowed(q) {
            var f = fold(q);
            for (var len = f.length - 1; len >= min; len--) {
                var hit = cache[f.slice(0, len)];
                if (!hit) { continue; }
                var prefix = f.slice(0, len);
                if (hit.extra > 0 || !hit.items.every(function (c) { return fold(c.name).indexOf(prefix) === 0; })) { return null; }
                var rows = hit.items.filter(function (c) { return fold(c.name).indexOf(f) === 0; });
                if (!rows.length) { return null; }
                rows = rows.filter(function (c) { return fold(c.name) === f; })
                    .concat(rows.filter(function (c) { return fold(c.name) !== f; }));
                return {items: rows, extra: 0};
            }
            return null;
        }

        function known(q) {
            return cache[fold(q)] || narrowed(q);
        }

        function load(q) {
            var hit = known(q);
            if (hit) { return Promise.resolve(hit); }
            if (!api || !api.url) { return Promise.reject(new Error('no api')); }
            var headers = {Accept: 'application/json'};
            headers[api.header] = api.token;
            var url = endpoint + joiner + 'q=' + encodeURIComponent(q.trim()) + '&limit=' + limit;
            return fetch(url, {credentials: 'same-origin', headers: headers})
                .then(function (r) {
                    if (!r.ok) { throw new Error('http ' + r.status); }
                    return r.json();
                })
                .then(function (body) {
                    var rows = body.data || [];
                    var found = {
                        items: rows.slice(0, limit),
                        extra: (parseInt((body.meta || {}).more, 10) || 0) + Math.max(0, rows.length - limit)
                    };
                    cache[fold(q)] = found;
                    return found;
                });
        }

        // The one exact match of the typed text, when the answer for it is known.
        function exactMatch() {
            var f = fold(input.value);
            var hit = cache[f];
            if (!hit) { return null; }
            var hits = hit.items.filter(function (c) { return fold(c.name) === f || fold(c.label) === f; });
            return hits.length === 1 ? hits[0] : null;
        }

        function autoPick() {
            if (picked || !input.value.trim()) { return; }
            var c = exactMatch();
            if (c) { pick(c, 'list'); }
        }

        ac = oscAutocomplete(input, {
            list: list,
            delay: 150,
            wrap: true,
            load: load,
            renderItem: optionRow,
            footer: footer,
            onSelect: function (c) { return pick(c, 'list'); },
            skip: function (q) {
                return fold(q).length < min || !!(picked && input.value === picked.label);
            },
            onSearch: function () {
                if (picked || ids.city.value) { unpick(); }
                typed.forEach(function (t) { if (t) { t.value = ''; } });
                sync();
            },
            onLoading: function (q) {
                if (known(q)) { return; }
                setBusy(true);
                if (list.hidden || !list.querySelector('.osc-ac-option')) { ac.show([statusRow(text('searching'))]); }
            },
            onResults: function (q, rows, more) {
                setBusy(false);
                say(rows.length ? fill(text('live-found'), rows.length + (more > 0 ? '+' : '')) : text('live-none'));
            },
            onError: failure,
            onClose: function () {
                setBusy(false);
                sync();
            },
            onBlur: function () {
                if (root.contains(document.activeElement)) { return false; }
                autoPick();
            }
        });
        if (!ac) { return; }

        if (clear) {
            clear.addEventListener('click', function () {
                unpick();
                input.value = '';
                ac.close();
                hideError();
                input.focus();
            });
        }

        if (savedBtn) {
            try {
                saved = JSON.parse(savedBtn.getAttribute('data-place'));
            } catch (e) {
                saved = null;
            }
            savedBtn.addEventListener('click', function () {
                if (!saved) { return; }
                pick({
                    id: saved.city_id,
                    label: saved.label,
                    region_id: saved.region_id,
                    country_code: saved.country_code
                }, saved.origin === 'account' ? 'account' : 'address');
                input.focus();
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                if (!strict || picked || !input.value.trim()) { return; }
                autoPick();
                if (picked) { return; }
                e.preventDefault();
                showError(text('pick-error'));
                input.focus();
            });
        }

        if (ids.city.value && input.value) {
            picked = {
                id: ids.city.value,
                label: input.value,
                region_id: ids.region.value,
                country_code: ids.country.value
            };
            origin = root.getAttribute('data-origin') || 'list';
        }
        if (err && !err.hidden && err.textContent) {
            showError(err.textContent);
        } else {
            hideError();
        }
        sync();
    }

    function initAll() {
        var roots = document.querySelectorAll('[data-osc-place]');
        for (var i = 0; i < roots.length; i++) { oscPlaceField(roots[i]); }
    }

    window.oscPlaceField = oscPlaceField;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
