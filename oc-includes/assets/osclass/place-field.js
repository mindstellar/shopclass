/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The place field, bound on every [data-osc-place] block by its style (single, fields or dropdown).
 * data-strict="1" refuses a submit of text not picked from the list.
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

    function textOf(root, key) {
        return root.getAttribute('data-text-' + key) || '';
    }

    function iconOf(root, name) {
        var icons = root.querySelector('template.oe-place__icons');
        var svg = icons && icons.content ? icons.content.querySelector('[data-icon="' + name + '"]') : null;
        return svg ? svg.cloneNode(true) : null;
    }

    function sayOf(root, msg) {
        var live = root.querySelector('.oe-place__live');
        if (live) { live.textContent = msg; }
    }

    function statusRow(msg, cls) {
        var li = el('li', 'oe-place__status' + (cls ? ' ' + cls : ''), msg);
        li.setAttribute('role', 'presentation');
        return li;
    }

    function savedOf(root) {
        var btn = root.querySelector('.oe-place__saved');
        try {
            return btn ? JSON.parse(btn.getAttribute('data-place')) : null;
        } catch (e) {
            return null;
        }
    }

    // City and region names of a saved place, from its own fields or its "City, Region" label.
    function savedNames(saved) {
        var cut = String(saved.label || '').lastIndexOf(', ');
        return {
            city: saved.city || (cut > -1 ? saved.label.slice(0, cut) : saved.label || ''),
            region: saved.region || (cut > -1 ? saved.label.slice(cut + 2) : '')
        };
    }

    // A REST list fetched with the page token, as {items, extra}: extra counts the matches past the limit.
    function restList(api, url, limit) {
        if (!api || !api.url) { return Promise.reject(new Error('no api')); }
        var headers = {Accept: 'application/json'};
        headers[api.header] = api.token;
        return fetch(url, {credentials: 'same-origin', headers: headers})
            .then(function (r) {
                if (!r.ok) { throw new Error('http ' + r.status); }
                return r.json();
            })
            .then(function (body) {
                var rows = body.data || [];
                return {
                    items: rows.slice(0, limit),
                    extra: (parseInt((body.meta || {}).more, 10) || 0) + Math.max(0, rows.length - limit) + (body.links && body.links.next ? 1 : 0)
                };
            });
    }

    /*
     * One box that suggests places: spinner, clear button, error line, status rows, retry and the
     * blur auto-pick. cfg gives what differs per style: load, cached, selected, picked, pick, skip,
     * onSearch, onClear, onClose and multi.
     */
    function placeBox(root, scope, cfg) {
        var input = scope.querySelector('.oe-place__input');
        var list = scope.querySelector('.osc-ac-list');
        if (!input || !list) { return null; }
        var strict = root.getAttribute('data-strict') === '1';
        var min = parseInt(root.getAttribute('data-min'), 10) || 2;
        var spin = scope.querySelector('.oe-place__spin');
        var clear = scope.querySelector('.oe-place__clear');
        var err = scope.querySelector('.oe-field-error');
        var errId = err && err.id ? err.id : '';
        var described = (input.getAttribute('aria-describedby') || '').split(/\s+/)
            .filter(function (id) { return id && id !== errId; }).join(' ');
        var uid = input.id || 'place';
        var box = {input: input, list: list, ac: null};

        box.syncClear = function () {
            if (clear) { clear.hidden = !input.value || (spin && !spin.hidden); }
        };

        box.busy = function (on) {
            if (spin) { spin.hidden = !on; }
            box.syncClear();
        };

        box.showError = function (msg) {
            if (!err) { return; }
            err.textContent = msg;
            err.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', (errId ? errId + ' ' : '') + described);
        };

        box.hideError = function () {
            if (err) { err.hidden = true; }
            input.removeAttribute('aria-invalid');
            input.setAttribute('aria-describedby', described);
        };

        // The one exact match of the typed text, when the answer for it is known.
        box.exact = function () {
            var f = fold(input.value);
            var hit = cfg.cached(input.value);
            if (!hit) { return null; }
            var hits = hit.items.filter(function (c) { return fold(c.name) === f || (!!c.label && fold(c.label) === f); });
            return hits.length === 1 ? hits[0] : null;
        };

        box.autoPick = function () {
            if (cfg.picked() || !input.value.trim()) { return; }
            var c = box.exact();
            if (c) { cfg.pick(c); }
        };

        function row(c, li, i, q) {
            var sel = cfg.selected(c);
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
            if (cfg.multi && c.country_code) {
                var cc = el('span', 'oe-place__cc', c.country_code);
                cc.setAttribute('title', c.country || c.country_code);
                li.appendChild(cc);
            }
            if (sel) {
                var tick = el('span', 'oe-place__tick');
                tick.replaceChildren.apply(tick, nodes(iconOf(root, 'tick')));
                li.appendChild(tick);
            }
        }

        function footer(q, rows, more) {
            if (!rows.length) {
                return [statusRow(fill(textOf(root, strict ? 'none-on' : 'none-off'), q.trim()))];
            }
            // The server stops counting at 100, so a count at the cap reads "100+".
            return more > 0 ? [statusRow(fill(textOf(root, 'more'), more >= 100 ? '100+' : more), 'oe-place__status--more')] : [];
        }

        function failure() {
            var li = statusRow('', 'oe-place__status--error');
            var retry = el('button', 'oe-place__retry', textOf(root, 'retry'));
            li.replaceChildren.apply(li, nodes(iconOf(root, 'alert'), el('span', '', textOf(root, strict ? 'error' : 'error-off')), retry));
            retry.setAttribute('type', 'button');
            retry.addEventListener('click', function () {
                input.focus();
                box.ac.search();
            });
            box.busy(false);
            sayOf(root, textOf(root, 'error'));
            return [li];
        }

        box.ac = oscAutocomplete(input, {
            list: list,
            delay: 150,
            wrap: true,
            load: cfg.load,
            renderItem: row,
            footer: footer,
            onSelect: function (c) { return cfg.pick(c); },
            skip: function (q) { return fold(q).length < min || cfg.skip(q); },
            onSearch: cfg.onSearch,
            onLoading: function (q) {
                if (cfg.cached(q)) { return; }
                box.busy(true);
                if (list.hidden || !list.querySelector('.osc-ac-option')) { box.ac.show([statusRow(textOf(root, 'searching'))]); }
            },
            onResults: function (q, rows, more) {
                box.busy(false);
                sayOf(root, rows.length ? fill(textOf(root, 'live-found'), rows.length + (more > 0 ? '+' : '')) : textOf(root, 'live-none'));
            },
            onError: failure,
            onClose: function () {
                box.busy(false);
                if (cfg.onClose) { cfg.onClose(); }
            },
            onBlur: function () {
                if (root.contains(document.activeElement)) { return false; }
                box.autoPick();
            }
        });
        if (!box.ac) { return null; }

        if (clear) {
            clear.addEventListener('click', function () {
                cfg.onClear();
                input.focus();
            });
        }
        if (err && !err.hidden && err.textContent) {
            box.showError(err.textContent);
        } else {
            box.hideError();
        }
        return box;
    }

    // The place field as one box that picks a city, with its region and country, from GET /cities.
    function bindSingle(root) {
        if (!root || root.getAttribute('data-osc-place-ready') || typeof oscAutocomplete !== 'function') { return; }
        var input = root.querySelector('.oe-place__input');
        if (!input || !root.querySelector('.osc-ac-list')) { return; }
        root.setAttribute('data-osc-place-ready', '1');

        var api = apiMeta();
        var strict = root.getAttribute('data-strict') === '1';
        var min = parseInt(root.getAttribute('data-min'), 10) || 2;
        var limit = Math.min(20, Math.max(1, parseInt(root.getAttribute('data-limit'), 10) || 8));
        var endpoint = (api && api.url ? api.url : '').replace(/\/$/, '') + '/cities';
        var joiner = endpoint.indexOf('?') > -1 ? '&' : '?';
        var note = root.querySelector('.oe-place__note');
        var savedBtn = root.querySelector('.oe-place__saved');
        var form = input.form || (root.closest ? root.closest('form') : null);
        var ids = {
            city: root.querySelector('input[name="cityId"]'),
            region: root.querySelector('input[name="regionId"]'),
            country: root.querySelector('input[name="countryId"]')
        };
        var typed = [root.querySelector('input[name="region"]'), root.querySelector('input[name="country"]')];
        var picked = null;
        var origin = '';
        var cache = {};
        var saved = savedOf(root);
        var box = null;

        function sync() {
            root.classList.toggle('is-picked', !!picked);
            if (note) {
                if (picked) {
                    note.className = 'oe-place__note oe-place__note--picked';
                    note.replaceChildren.apply(note, nodes(iconOf(root, 'tick'), el('span', '', textOf(root, 'note-' + (origin || 'list')))));
                    note.hidden = false;
                } else if (!strict && input.value.trim() && box.list.hidden) {
                    note.className = 'oe-place__note';
                    note.replaceChildren(el('span', '', textOf(root, 'note-typed')));
                    note.hidden = false;
                } else {
                    note.replaceChildren();
                    note.hidden = true;
                }
            }
            if (savedBtn) {
                savedBtn.hidden = !saved || !!(picked && String(picked.id) === String(saved.city_id));
            }
            box.syncClear();
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
            box.hideError();
            box.ac.close();
            if (c && origin === 'list') { sayOf(root, fill(textOf(root, 'live-chosen'), c.label)); }
            return false;
        }

        function unpick() {
            picked = null;
            origin = '';
            setIds(null);
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
            return restList(api, endpoint + joiner + 'q=' + encodeURIComponent(q.trim()) + '&limit=' + limit, limit)
                .then(function (found) {
                    cache[fold(q)] = found;
                    return found;
                });
        }

        box = placeBox(root, root, {
            multi: root.getAttribute('data-countries') === 'n',
            load: load,
            cached: known,
            selected: function (c) { return !!(picked && String(picked.id) === String(c.id)); },
            picked: function () { return !!picked; },
            pick: function (c) { return pick(c, 'list'); },
            skip: function () { return !!(picked && input.value === picked.label); },
            onSearch: function () {
                if (picked || ids.city.value) { unpick(); }
                typed.forEach(function (t) { if (t) { t.value = ''; } });
                sync();
            },
            onClose: sync,
            onClear: function () {
                unpick();
                input.value = '';
                box.ac.close();
                box.hideError();
            }
        });
        if (!box) { return; }

        if (savedBtn) {
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
                box.autoPick();
                if (picked) { return; }
                e.preventDefault();
                box.showError(textOf(root, 'pick-error'));
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
        sync();
    }

    // A level's error line: the message, and a Try again button when a retry callback is given.
    function showLevelError(root, part, msg, retry) {
        var err = part.querySelector('.oe-field-error');
        if (!err) { return; }
        var parts = [el('span', '', msg)];
        if (retry) {
            var btn = el('button', 'oe-place__retry', textOf(root, 'retry'));
            btn.setAttribute('type', 'button');
            btn.addEventListener('click', retry);
            parts.push(btn);
        }
        err.replaceChildren.apply(err, parts);
        err.hidden = false;
    }

    // The place field with a country select and a box each for region and city.
    function bindFields(root) {
        if (root.getAttribute('data-osc-place-ready') || typeof oscAutocomplete !== 'function') { return; }
        root.setAttribute('data-osc-place-ready', '1');

        var api = apiMeta();
        var strict = root.getAttribute('data-strict') === '1';
        var limit = Math.min(20, Math.max(1, parseInt(root.getAttribute('data-limit'), 10) || 8));
        var endpoint = (api && api.url ? api.url : '').replace(/\/$/, '');
        var countryEl = root.querySelector('[name="countryId"]');
        var countryText = root.querySelector('input[name="country"]');
        var savedBtn = root.querySelector('.oe-place__saved');
        var form = root.closest ? root.closest('form') : null;
        var saved = savedOf(root);
        var levels = {};
        var order = [];

        function code() {
            return countryEl ? countryEl.value : '';
        }

        function regionId() {
            return levels.region ? levels.region.idEl.value : '';
        }

        function clearText() {
            if (countryText) { countryText.value = ''; }
        }

        function syncSaved() {
            if (!savedBtn) { return; }
            var city = levels.city ? levels.city.idEl.value : '';
            savedBtn.hidden = !saved || (!!city && String(city) === String(saved.city_id));
        }

        function setLevel(L, name, id) {
            L.box.input.value = name;
            L.idEl.value = id;
            L.text = name;
            L.box.hideError();
            L.box.syncClear();
        }

        function emptyLevel(L) {
            setLevel(L, '', '');
            L.box.ac.close();
        }

        // The search for a level, from the country and region now chosen.
        function request(L, q) {
            var term = 'q=' + encodeURIComponent(q.trim());
            var path;
            var query;
            if (L.name === 'region') {
                path = '/countries/' + encodeURIComponent(code()) + '/regions';
                query = term + '&limit=100';
            } else if (regionId()) {
                path = '/regions/' + encodeURIComponent(regionId()) + '/cities';
                query = term + '&limit=100';
            } else {
                path = '/cities';
                query = term + '&limit=' + limit + (code() ? '&country=' + encodeURIComponent(code()) : '');
            }
            var url = endpoint + path;
            return url + (url.indexOf('?') > -1 ? '&' : '?') + query;
        }

        function load(L, q) {
            var url = request(L, q);
            if (L.cache[url]) { return Promise.resolve(L.cache[url]); }
            return restList(api, url, limit).then(function (found) {
                L.cache[url] = found;
                return found;
            });
        }

        function setCountry(c) {
            if (countryEl && c && countryEl.value !== c) { countryEl.value = c; }
            clearText();
        }

        // Returns false so oscAutocomplete leaves the input to this field.
        function pick(L, c) {
            setLevel(L, c.name, c.id);
            if (L.name === 'region') {
                if (levels.city) { emptyLevel(levels.city); }
            } else if (levels.region && c.region_id) {
                setLevel(levels.region, c.region || levels.region.box.input.value, c.region_id);
            }
            setCountry(c.country_code);
            L.box.ac.close();
            syncSaved();
            sayOf(root, fill(textOf(root, 'live-chosen'), c.label || c.name));
            return false;
        }

        function bind(name) {
            var part = root.querySelector('.oe-place__part[data-level="' + name + '"]');
            var idEl = root.querySelector('input[name="' + name + 'Id"]');
            if (!part || !idEl) { return; }
            var L = {name: name, idEl: idEl, text: '', cache: {}, box: null};
            L.box = placeBox(root, part, {
                multi: false,
                load: function (q) { return load(L, q); },
                cached: function (q) { return L.cache[request(L, q)]; },
                selected: function (c) { return String(idEl.value) === String(c.id); },
                picked: function () { return !!idEl.value; },
                pick: function (c) { return pick(L, c); },
                skip: function () {
                    return (name === 'region' && !code()) || !!(idEl.value && L.box.input.value === L.text);
                },
                onSearch: function () {
                    idEl.value = '';
                    L.text = '';
                    if (name === 'region' && levels.city) { emptyLevel(levels.city); }
                    L.box.hideError();
                    syncSaved();
                    L.box.syncClear();
                },
                onClear: function () {
                    if (name === 'region' && levels.city) { emptyLevel(levels.city); }
                    emptyLevel(L);
                    syncSaved();
                }
            });
            if (!L.box) { return; }
            levels[name] = L;
            order.push(name);
            if (idEl.value && L.box.input.value) { L.text = L.box.input.value; }
            L.box.syncClear();
        }

        bind('region');
        bind('city');

        if (countryEl && countryEl.addEventListener) {
            countryEl.addEventListener('change', function () {
                order.forEach(function (n) { emptyLevel(levels[n]); });
                clearText();
                syncSaved();
            });
        }

        if (savedBtn && saved) {
            savedBtn.addEventListener('click', function () {
                var names = savedNames(saved);
                setCountry(saved.country_code);
                if (levels.region) { setLevel(levels.region, names.region, saved.region_id || ''); }
                if (levels.city) { setLevel(levels.city, names.city, saved.city_id || ''); }
                syncSaved();
                if (levels.city) { levels.city.box.input.focus(); }
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                if (!strict) { return; }
                var first = null;
                order.forEach(function (n) {
                    var L = levels[n];
                    L.box.autoPick();
                    if (L.box.input.value.trim() && !L.idEl.value) {
                        L.box.showError(textOf(root, 'pick-error'));
                        first = first || L;
                    }
                });
                if (!first) { return; }
                e.preventDefault();
                first.box.input.focus();
            });
        }
        syncSaved();
    }

    /*
     * The place field as three selects that fill each other from the core ajax `regions` and `cities`
     * lists. The [data-ac] binder in ui-common.js suggests a few matches for typed text, so it cannot fill a select.
     */
    function bindDropdown(root) {
        if (root.getAttribute('data-osc-place-ready')) { return; }
        root.setAttribute('data-osc-place-ready', '1');

        var form = root.closest ? root.closest('form') : null;
        var base = root.getAttribute('data-ajax') || (form && form.getAttribute('action')) || '';
        var strict = root.getAttribute('data-strict') === '1';
        var countryEl = root.querySelector('[name="countryId"]');
        var savedBtn = root.querySelector('.oe-place__saved');
        var saved = savedOf(root);
        var seq = {region: 0, city: 0};
        var param = {region: 'regionId', city: 'cityId'};

        function part(level) {
            return root.querySelector('.oe-place__part[data-level="' + level + '"]');
        }

        function control(level) {
            var p = part(level);
            if (!p) { return null; }
            return p.querySelector('select') || p.querySelector('input[name="' + level + '"]');
        }

        function select(level) {
            var c = control(level);
            return c && c.tagName === 'SELECT' ? c : null;
        }

        function say(msg) {
            sayOf(root, msg);
        }

        function clearError(level) {
            var p = part(level);
            var err = p ? p.querySelector('.oe-field-error') : null;
            var c = control(level);
            if (err) { err.hidden = true; }
            if (c) { c.removeAttribute('aria-invalid'); }
        }

        function fail(level, retry) {
            var p = part(level);
            var c = control(level);
            showLevelError(root, p, textOf(root, 'error'), retry);
            if (c) { c.setAttribute('aria-invalid', 'true'); }
            say(textOf(root, 'error'));
        }

        // The select back to its placeholder, and off when it has no parent value to fill from.
        function reset(level, off) {
            var s = select(level);
            if (!s) { return; }
            var ph = s.children[0] || el('option');
            if (!s.children[0]) { ph.value = ''; }
            s.replaceChildren(ph);
            s.value = '';
            s.disabled = off;
            s.removeAttribute('aria-busy');
            clearError(level);
        }

        function busy(level, on) {
            var s = select(level);
            if (!s) { return; }
            s.disabled = on;
            if (on) { s.setAttribute('aria-busy', 'true'); } else { s.removeAttribute('aria-busy'); }
        }

        // A level with nothing to list becomes a text box (option off) or stays an empty, disabled select (option on).
        function toText(level) {
            var s = select(level);
            if (!s || strict) { return; }
            var box = document.createElement('input');
            box.setAttribute('type', 'text');
            box.setAttribute('name', level);
            box.id = s.id;
            box.className = 'oe-place__text';
            s.replaceWith(box);
        }

        // A text box back to a select; the hidden id drawn with the box goes, so the select's value is the one posted.
        function toSelect(level) {
            var p = part(level);
            var box = p ? p.querySelector('input[name="' + level + '"]') : null;
            if (!box) { return; }
            var id = p.querySelector('input[type="hidden"][name="' + param[level] + '"]');
            if (id) { id.remove(); }
            var s = document.createElement('select');
            s.setAttribute('name', param[level]);
            s.id = box.id;
            s.className = 'oe-place__select';
            s.appendChild(el('option', '', ''));
            s.children[0].value = '';
            box.replaceWith(s);
        }

        function post(action, key, value) {
            var params = {};
            params[key] = value;
            return fetch(oscAjaxUrl(base, action, params), {method: 'POST', credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function (r) {
                    if (!r.ok) { throw new Error('http ' + r.status); }
                    return r.json();
                });
        }

        function fillOptions(s, rows) {
            var ph = s.children[0];
            var opts = rows.map(function (r) {
                var o = el('option', '', r.s_name);
                o.value = String(r.pk_i_id);
                return o;
            });
            s.replaceChildren.apply(s, [ph].concat(opts));
            s.value = '';
        }

        // Loads one level's list for its parent; a newer load or a parent change drops this answer.
        function loadLevel(level, action, key, value) {
            var mine = ++seq[level];
            if (!value) {
                reset(level, true);
                return Promise.resolve();
            }
            toSelect(level);
            reset(level, true);
            busy(level, true);
            say(textOf(root, 'loading'));
            return post(action, key, value).then(function (rows) {
                if (mine !== seq[level]) { return; }
                if (!Array.isArray(rows) || !rows.length) {
                    busy(level, false);
                    toText(level);
                    if (select(level)) { select(level).disabled = true; }
                    return;
                }
                var s = select(level);
                fillOptions(s, rows);
                busy(level, false);
            }, function () {
                if (mine !== seq[level]) { return; }
                var s = select(level);
                if (s) { s.disabled = true; s.removeAttribute('aria-busy'); }
                fail(level, function () { loadLevel(level, action, key, value); });
            });
        }

        function loadRegions(codeValue) {
            seq.city++;
            toSelect('city');
            reset('city', true);
            var mine = seq.region + 1;
            return loadLevel('region', 'regions', 'countryId', codeValue).then(function () {
                if (mine === seq.region && codeValue && !select('region') && part('region')) { toText('city'); }
            });
        }

        function loadCities(regionValue) {
            return loadLevel('city', 'cities', 'regionId', regionValue);
        }

        function syncSaved() {
            if (!savedBtn) { return; }
            var c = select('city');
            savedBtn.hidden = !saved || (!!c && String(c.value) === String(saved.city_id));
        }

        root.addEventListener('change', function (e) {
            var t = e.target;
            if (!t) { return; }
            if (t === countryEl) {
                loadRegions(countryEl.value).then(syncSaved);
            } else if (t.getAttribute && t.getAttribute('name') === 'regionId') {
                loadCities(t.value).then(syncSaved);
            } else {
                syncSaved();
            }
        });

        if (savedBtn && saved) {
            savedBtn.addEventListener('click', function () {
                var names = savedNames(saved);
                if (countryEl) { countryEl.value = saved.country_code; }
                loadRegions(saved.country_code).then(function () {
                    var r = select('region');
                    if (!r) { return; }
                    if (saved.region_id) { r.value = String(saved.region_id); }
                    return loadCities(saved.region_id ? String(saved.region_id) : '');
                }).then(function () {
                    var c = select('city');
                    if (c && saved.city_id) { c.value = String(saved.city_id); }
                    syncSaved();
                    say(fill(textOf(root, 'live-chosen'), names.city + (names.region ? ', ' + names.region : '')));
                });
            });
        }
        syncSaved();
    }

    function oscPlaceField(root) {
        if (!root) { return; }
        var style = root.getAttribute('data-osc-place');
        if (style === 'fields') {
            bindFields(root);
        } else if (style === 'dropdown') {
            bindDropdown(root);
        } else {
            bindSingle(root);
        }
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
