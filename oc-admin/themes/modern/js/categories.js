/*
 * Categories screen: the tree table in categories/index.php. Rows are drawn once by the server;
 * this file keeps one flat model of them and drives disclosure, status tabs, search, selection,
 * totals, the edit drawer, row and bulk actions, and sibling drag. Endpoints and copy arrive as
 * data-* on .categories-app.
 */
/* global oscInitTabs, oscFill */
(function () {
    'use strict';

    const HEADERS = {'X-Requested-With': 'XMLHttpRequest'};

    // Case- and accent-insensitive key for search.
    function norm(s) {
        return String(s).toLowerCase().normalize('NFD').replace(/\p{M}/gu, '');
    }

    function fmt(n) {
        return Number(n || 0).toLocaleString(document.documentElement.lang || undefined);
    }

    function el(tag, attrs, kids) {
        const node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') {
                node.textContent = attrs[k];
            } else {
                node.setAttribute(k, attrs[k]);
            }
        });
        (kids || []).forEach(function (kid) {
            if (kid) {
                node.append(kid);
            }
        });
        return node;
    }

    function init() {
        const app = document.querySelector('.categories-app');
        if (!app) {
            return;
        }

        const CFG = {
            edit: app.dataset.editUrl || '',
            enable: app.dataset.enableUrl || '',
            del: app.dataset.deleteUrl || '',
            move: app.dataset.moveUrl || '',
            bulk: app.dataset.bulkUrl || '',
            preview: app.dataset.previewUrl || '',
            limit: parseInt(app.dataset.depthLimit, 10) || 0
        };
        let I18N = {};
        try {
            I18N = JSON.parse(app.dataset.i18n || '{}');
        } catch {
            I18N = {};
        }

        // A plural table from the server ({forms, idx} for n = 0..199), or a plain string, filled with n.
        function plural(key, n) {
            const p = I18N[key];
            let tpl = p;
            if (p && Array.isArray(p.forms)) {
                const i = n < 200 ? n : 100 + (n % 100);
                tpl = p.forms[+p.idx.charAt(i) || 0];
            }
            return oscFill(tpl, [fmt(n)]);
        }

        const tbody = document.getElementById('cat-rows');
        const checkAll = document.getElementById('cat-check-all');
        const search = document.getElementById('cat-q');
        const filterLine = document.getElementById('cat-filter-line');
        const announcer = document.getElementById('cat-announce');
        const selNote = app.querySelector('.cat-selnote');
        const selCount = app.querySelector('[data-cat-selcount]');
        const footSum = document.getElementById('cat-foot-sum');
        const drawer = document.getElementById('catDrawer');
        const backdrop = document.getElementById('catDrawerBackdrop');
        const drawerBody = document.getElementById('catDrawerBody');
        const drawerTitle = document.getElementById('catDrawerTitle');
        const drawerSub = document.getElementById('catDrawerSub');
        const closeBtn = document.getElementById('catDrawerClose');

        function flash(type, text) {
            if (typeof window.setJsMessage === 'function' && text && document.getElementById('jsMessage')) {
                window.setJsMessage(type, text);
            }
        }

        function announce(text) {
            if (!announcer) {
                return;
            }
            announcer.textContent = '';
            window.requestAnimationFrame(function () {
                announcer.textContent = text || '';
            });
        }

        // One request path for the screen: a body makes it a POST; replies are JSON (null when
        // unreadable) unless text is asked for.
        function request(url, body, asText) {
            const init = {credentials: 'same-origin', headers: HEADERS};
            if (body !== undefined) {
                init.method = 'POST';
                init.body = body;
            }
            return fetch(url, init)
                .then(function (r) {
                    return r.text();
                })
                .then(function (text) {
                    if (asText) {
                        return text;
                    }
                    try {
                        return JSON.parse(text);
                    } catch {
                        return null;
                    }
                });
        }

        function post(url, body) {
            return request(url, body);
        }

        // --- Model: one flat pre-order array of the rows --------------------------------------
        let rows = [];
        let roots = [];
        const byId = new Map();
        const selected = new Set();

        function readRows() {
            if (!tbody) {
                return;
            }
            for (const tr of tbody.children) {
                if (!tr.classList.contains('cat-row')) {
                    continue;
                }
                const d = tr.dataset;
                const nameEl = tr.querySelector('.cat-name');
                const name = nameEl ? nameEl.textContent : '';
                const node = {
                    id: +d.id,
                    parent: +d.parent || 0,
                    depth: +d.depth || 1,
                    height: +d.height || 1,
                    subs: +d.subs || 0,
                    below: +d.below || 0,
                    listings: +d.listings || 0,
                    expiry: +d.expiry || 0,
                    enabled: d.enabled === '1',
                    el: tr,
                    nameEl: nameEl,
                    name: name,
                    key: norm(name),
                    kids: [],
                    open: false,
                    shown: !tr.hidden,
                    context: false,
                    hl: '',
                    exp: false,
                    mark: false
                };
                byId.set(node.id, node);
                // Rows come in tree order, so a parent is always read before its children.
                const parent = byId.get(node.parent);
                if (parent) {
                    parent.kids.push(node);
                } else {
                    node.parent = 0;
                    roots.push(node);
                }
                rows.push(node);
            }
        }

        // Rebuilds the pre-order array from the kids lists.
        function reorder() {
            const out = [];
            const walk = function (list) {
                list.forEach(function (node) {
                    out.push(node);
                    walk(node.kids);
                });
            };
            walk(roots);
            rows = out;
        }

        function ancestors(node) {
            const out = [];
            let p = byId.get(node.parent);
            while (p) {
                out.push(p);
                p = byId.get(p.parent);
            }
            return out;
        }

        function subtree(node) {
            const out = [node];
            for (let i = 0; i < out.length; i++) {
                out.push.apply(out, out[i].kids);
            }
            return out;
        }

        // What a delete or disable reaches: a row's listings are already its subtree total.
        function subtreeCounts(node) {
            return {subs: node.below, items: node.listings};
        }

        // --- Row cells ----------------------------------------------------------------------
        function setNum(td, n, nil) {
            if (!td) {
                return;
            }
            if (nil && n === 0) {
                td.replaceChildren(el('span', {class: 'cat-nil', text: '–'}));
            } else {
                td.textContent = fmt(n);
            }
        }

        function setExpiryCell(node) {
            node.el.dataset.expiry = node.expiry;
            const td = node.el.querySelector('.col-expiry');
            if (td) {
                td.textContent = node.expiry > 0 ? plural('daysN', node.expiry) : I18N.never;
            }
        }

        function setDisclosure(node) {
            const line = node.el.querySelector('.cat-name-line');
            const disc = line && line.querySelector('.cat-disclosure');
            const spacer = line && line.querySelector('.cat-disclosure-spacer');
            if (node.kids.length && spacer) {
                const btn = el('button', {
                    type: 'button',
                    class: 'cat-disclosure',
                    'aria-expanded': 'false',
                    'aria-label': I18N.showSubs || '',
                    'aria-describedby': 'cat-n-' + node.id
                }, [el('i', {class: 'bi bi-chevron-right', 'aria-hidden': 'true'})]);
                if (filtering()) {
                    btn.setAttribute('aria-disabled', 'true');
                }
                spacer.replaceWith(btn);
                node.exp = false;
            } else if (!node.kids.length && disc) {
                disc.replaceWith(el('span', {class: 'cat-disclosure-spacer'}));
                node.open = false;
            }
        }

        function setExpanded(node, expanded) {
            if (node.exp === expanded) {
                return;
            }
            node.exp = expanded;
            const disc = node.el.querySelector('.cat-disclosure');
            if (disc) {
                disc.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                disc.setAttribute('aria-label', (expanded ? I18N.hideSubs : I18N.showSubs) || '');
            }
        }

        function setEnabled(node, on) {
            node.enabled = on;
            node.el.dataset.enabled = on ? '1' : '0';
            node.el.classList.toggle('is-disabled', !on);
            const status = node.el.querySelector('.col-status');
            if (status) {
                status.replaceChildren(on
                    ? el('span', {class: 'visually-hidden', text: I18N.enabled})
                    : el('span', {class: 'cat-off'}, [
                        el('i', {class: 'bi bi-dash-circle', 'aria-hidden': 'true'}),
                        document.createTextNode(I18N.disabled)
                    ]));
            }
            const toggle = node.el.querySelector('[data-cat-act="toggle"]');
            if (toggle) {
                toggle.textContent = on ? I18N.disable : I18N.enable;
            }
        }

        function rename(node, name) {
            node.name = name;
            node.key = norm(name);
            node.hl = null;
            node.nameEl.textContent = name;
        }

        // Recomputes heights and counts from the kids lists and writes changed ones to the rows.
        function syncRows() {
            for (let i = rows.length - 1; i >= 0; i--) {
                const node = rows[i];
                let h = 1;
                let below = 0;
                node.kids.forEach(function (k) {
                    h = Math.max(h, k.height + 1);
                    below += k.below + 1;
                });
                node.height = h;
                node.below = below;
                node.subs = node.kids.length;
            }
            rows.forEach(function (node) {
                const d = node.el.dataset;
                const p = byId.get(node.parent);
                node.depth = p ? p.depth + 1 : 1;
                if (+d.depth !== node.depth) {
                    d.depth = node.depth;
                    node.el.style.setProperty('--cat-depth', String(node.depth - 1));
                    const canAdd = !CFG.limit || node.depth < CFG.limit;
                    node.el.toggleAttribute('data-no-child', !canAdd);
                    node.el.classList.toggle('is-deep', !!CFG.limit && node.depth > CFG.limit);
                    const add = node.el.querySelector('[data-cat-act="addsub"]');
                    if (add) {
                        add.disabled = !canAdd;
                        if (canAdd) {
                            add.removeAttribute('title');
                        } else if (I18N.depthNote) {
                            add.title = I18N.depthNote;
                        }
                    }
                }
                if (+d.parent !== node.parent) {
                    d.parent = node.parent;
                }
                if (+d.height !== node.height) {
                    d.height = node.height;
                }
                if (+d.below !== node.below) {
                    d.below = node.below;
                }
                if (+d.subs !== node.subs) {
                    d.subs = node.subs;
                    setNum(node.el.querySelector('.col-subs'), node.subs, true);
                    setDisclosure(node);
                }
                if (+d.listings !== node.listings) {
                    d.listings = node.listings;
                    setNum(node.el.querySelector('.col-listings'), node.listings, false);
                }
            });
            totals();
        }

        // Moves a category with its subcategories under newParent (0 = top) at index (past the
        // end = last). Listing totals shift along the old and new ancestor chains by its own count.
        function relocate(id, newParent, index) {
            const node = byId.get(id);
            if (!node) {
                return;
            }
            const size = subtree(node).length;
            const oldList = node.parent ? byId.get(node.parent).kids : roots;
            ancestors(node).forEach(function (a) {
                a.listings -= node.listings;
            });
            oldList.splice(oldList.indexOf(node), 1);
            const target = byId.get(newParent);
            node.parent = target ? target.id : 0;
            const list = target ? target.kids : roots;
            list.splice(Math.max(0, Math.min(index, list.length)), 0, node);
            ancestors(node).forEach(function (a) {
                a.listings += node.listings;
            });
            reorder();
            const at = rows.indexOf(node);
            const next = rows[at + size];
            rows.slice(at, at + size).forEach(function (n) {
                tbody.insertBefore(n.el, next ? next.el : stateRow());
            });
            syncRows();
            syncParentTemplate();
            refilter();
        }

        // Takes a deleted category and its subcategories off the page.
        function removeBlock(id) {
            const node = byId.get(id);
            if (!node) {
                return;
            }
            ancestors(node).forEach(function (a) {
                a.listings -= node.listings;
            });
            const list = node.parent ? byId.get(node.parent).kids : roots;
            list.splice(list.indexOf(node), 1);
            subtree(node).forEach(function (n) {
                n.el.remove();
                byId.delete(n.id);
                selected.delete(n.id);
            });
            reorder();
            syncRows();
            syncParentTemplate();
            refilter();
        }

        // Keeps the Parent options template in tree order with the current names and depths.
        function syncParentTemplate() {
            const tpl = document.getElementById('catParentOptions');
            if (!tpl) {
                return;
            }
            const opts = new Map();
            let top = null;
            tpl.content.querySelectorAll('option').forEach(function (o) {
                if (o.value === '0') {
                    top = o;
                } else {
                    opts.set(+o.value, o);
                }
            });
            const frag = document.createDocumentFragment();
            if (top) {
                frag.append(top);
            }
            rows.forEach(function (node) {
                const o = opts.get(node.id) || el('option', {value: String(node.id)});
                o.dataset.depth = node.depth;
                o.dataset.height = node.height;
                o.textContent = ' '.repeat(4 * (node.depth - 1)) + node.name;
                frag.append(o);
            });
            tpl.content.replaceChildren(frag);
        }

        // Fills a parent select from the template. selfId's own subtree cannot be picked, and with
        // a theme depth limit neither can a parent that would put it deeper than both the limit
        // and where it sits now. Returns whether any option was refused for depth.
        function fillParents(select, selfId, selectedId) {
            const tpl = document.getElementById('catParentOptions');
            if (!tpl || !select) {
                return false;
            }
            const selves = (Array.isArray(selfId) ? selfId : [selfId]).map(function (id) {
                return byId.get(id);
            }).filter(Boolean);
            const banned = new Set();
            let h = 1;
            let today = 0;
            selves.forEach(function (self) {
                subtree(self).forEach(function (n) {
                    banned.add(n.id);
                });
                h = Math.max(h, self.height);
                today = Math.max(today, self.depth + self.height - 1);
            });
            let deep = false;
            const frag = tpl.content.cloneNode(true);
            frag.querySelectorAll('option').forEach(function (o) {
                const id = +o.value;
                const d = id === 0 ? 0 : (+o.dataset.depth || 1);
                if (banned.has(id)) {
                    o.disabled = true;
                } else if (CFG.limit && d + h > CFG.limit && d + h > today) {
                    o.disabled = true;
                    o.textContent += ' ' + (I18N.tooDeep || '');
                    deep = true;
                }
            });
            select.replaceChildren(frag);
            select.value = String(selectedId || 0);
            return deep;
        }

        // --- Tabs, totals -------------------------------------------------------------------
        const state = {tab: app.dataset.status || '', q: '', raw: ''};
        const tabs = Array.from(app.querySelectorAll('.cat-tabs a'));

        function tabOf(a) {
            try {
                return new URL(a.href, window.location.href).searchParams.get('status') || '';
            } catch {
                return '';
            }
        }

        function totals() {
            let on = 0;
            rows.forEach(function (n) {
                on += n.enabled ? 1 : 0;
            });
            const counts = {'': rows.length, on: on, off: rows.length - on};
            tabs.forEach(function (a) {
                const badge = a.querySelector('.osc-count');
                if (badge) {
                    const n = counts[tabOf(a)] || 0;
                    badge.textContent = fmt(n);
                    badge.classList.toggle('is-zero', n === 0);
                }
            });
            if (footSum) {
                let listings = 0;
                roots.forEach(function (n) {
                    listings += n.listings;
                });
                footSum.textContent = oscFill(I18N.footSum, [
                    plural('categoriesN', rows.length),
                    plural('groupsN', roots.length),
                    plural('listingsN', listings)
                ]);
            }
        }

        function setTab(tab) {
            state.tab = tab;
            tabs.forEach(function (a) {
                const on = tabOf(a) === tab;
                a.classList.toggle('is-active', on);
                if (on) {
                    a.setAttribute('aria-current', 'page');
                } else {
                    a.removeAttribute('aria-current');
                }
            });
            try {
                const u = new URL(window.location.href);
                if (tab) {
                    u.searchParams.set('status', tab);
                } else {
                    u.searchParams.delete('status');
                }
                window.history.replaceState(window.history.state, '', u.toString());
            } catch {
                // A page the browser will not rewrite keeps its address.
            }
            refilter();
        }

        // --- Filter pass --------------------------------------------------------------------
        // A row shows when every ancestor is open; while a tab or search filters, it shows when
        // it matches or holds a match (a grey context row). Only rows whose state flips are touched.
        let frame = 0;
        let wasFiltering = false;
        let noResult = null;

        function filtering() {
            return state.q !== '' || state.tab !== '';
        }

        function stateRow() {
            if (!noResult) {
                noResult = el('tr', {class: 'cat-state', hidden: ''}, [el('td', {colspan: '6'})]);
                tbody.append(noResult);
            }
            return noResult;
        }

        function highlight(node, q) {
            if (!q) {
                node.nameEl.textContent = node.name;
                return;
            }
            let key = '';
            const map = [];
            for (let i = 0; i < node.name.length; i++) {
                const k = norm(node.name[i]);
                for (let j = 0; j < k.length; j++) {
                    map.push(i);
                }
                key += k;
            }
            const at = key.indexOf(q);
            if (at < 0) {
                node.nameEl.textContent = node.name;
                return;
            }
            const start = map[at];
            const end = map[at + q.length - 1] + 1;
            node.nameEl.replaceChildren(
                document.createTextNode(node.name.slice(0, start)),
                el('mark', {text: node.name.slice(start, end)}),
                document.createTextNode(node.name.slice(end))
            );
        }

        function refilter() {
            if (frame) {
                return;
            }
            frame = window.requestAnimationFrame(runFilter);
        }

        function runFilter() {
            frame = 0;
            const t0 = performance.now();
            const f = filtering();
            const n = rows.length;
            let matches = 0;
            let shown = 0;
            for (let i = 0; i < n; i++) {
                const node = rows[i];
                node.mark = f && (state.tab === '' || (state.tab === 'on') === node.enabled)
                    && (state.q === '' || node.key.indexOf(state.q) !== -1);
                node.holds = false;
            }
            if (f) {
                for (let i = n - 1; i >= 0; i--) {
                    if (rows[i].mark || rows[i].holds) {
                        const p = byId.get(rows[i].parent);
                        if (p) {
                            p.holds = true;
                        }
                    }
                }
            }
            for (let i = 0; i < n; i++) {
                const node = rows[i];
                let want;
                if (f) {
                    want = node.mark || node.holds;
                    matches += node.mark ? 1 : 0;
                    setExpanded(node, node.holds);
                } else {
                    const p = byId.get(node.parent);
                    want = !p || (p.open && p.shown);
                    setExpanded(node, node.open);
                }
                if (node.shown !== want || node.el.hidden === want) {
                    node.shown = want;
                    node.el.hidden = !want;
                }
                const ctx = f && !node.mark && node.holds;
                if (node.context !== ctx) {
                    node.context = ctx;
                    node.el.classList.toggle('is-context', ctx);
                }
                const hl = node.mark && state.q ? state.q : '';
                if (node.hl !== hl) {
                    node.hl = hl;
                    highlight(node, hl);
                }
                shown += want ? 1 : 0;
            }
            if (f !== wasFiltering) {
                wasFiltering = f;
                rows.forEach(function (node) {
                    node.el.querySelectorAll('.cat-handle, .cat-disclosure').forEach(function (b) {
                        if (f) {
                            b.setAttribute('aria-disabled', 'true');
                        } else {
                            b.removeAttribute('aria-disabled');
                        }
                    });
                });
                app.querySelectorAll('[data-cat-act="expand-all"], [data-cat-act="collapse-all"]').forEach(function (b) {
                    b.disabled = f;
                });
            }
            drawState(f, matches);
            drawFilterLine(f, matches);
            syncSel();
            const ms = performance.now() - t0;
            app.dispatchEvent(new CustomEvent('cat:filtered', {detail: {shown: shown, matches: matches, ms: ms}}));
        }

        function tabSentence() {
            return state.tab ? (state.tab === 'on' ? I18N.filterOn : I18N.filterOff) : '';
        }

        // The no-results row: Clear search and Add "…" for a search, one quiet line for a tab.
        function drawState(f, matches) {
            if (!tbody || (!noResult && (!f || matches > 0))) {
                return;
            }
            const tr = stateRow();
            tr.hidden = !f || matches > 0 || rows.length === 0;
            if (tr.hidden) {
                return;
            }
            const td = tr.firstChild;
            if (state.q) {
                td.replaceChildren(
                    el('p', {}, [el('strong', {text: oscFill(I18N.noMatch, [state.raw])})]),
                    el('div', {class: 'd-flex flex-wrap gap-2'}, [
                        el('button', {type: 'button', class: 'btn btn-dim btn-sm', 'data-cat-act': 'clear-search', text: I18N.clearSearch}),
                        el('button', {type: 'button', class: 'btn btn-dim btn-sm', 'data-cat-act': 'add-from-search', text: oscFill(I18N.addAs, [state.raw])})
                    ])
                );
            } else {
                td.replaceChildren(el('p', {text: state.tab === 'on' ? I18N.emptyOn : I18N.emptyOff}));
            }
        }

        function drawFilterLine(f, matches) {
            if (!filterLine) {
                return;
            }
            if (!f || matches === 0) {
                filterLine.hidden = true;
                if (f && state.q) {
                    announce(oscFill(I18N.noMatch, [state.raw]));
                }
                return;
            }
            const text = [
                plural('filterShown', matches),
                state.q ? oscFill(I18N.filterSearch, [state.raw]) : '',
                tabSentence(),
                I18N.filterNoReorder
            ].filter(Boolean).join(' ');
            filterLine.replaceChildren(
                el('span', {text: text}),
                el('button', {type: 'button', class: 'btn-link', 'data-cat-act': 'show-all', text: I18N.showAll})
            );
            filterLine.hidden = false;
            announce(text);
        }

        function clearSearch(focus) {
            if (search) {
                search.value = '';
            }
            state.q = '';
            state.raw = '';
            refilter();
            if (focus && search) {
                search.focus();
            }
        }

        // --- Selection ----------------------------------------------------------------------
        function syncSel() {
            let shown = 0;
            let on = 0;
            rows.forEach(function (node) {
                if (node.shown) {
                    shown++;
                    on += selected.has(node.id) ? 1 : 0;
                }
            });
            if (checkAll) {
                checkAll.checked = shown > 0 && on === shown;
                checkAll.indeterminate = on > 0 && on < shown;
            }
            if (selNote) {
                selNote.hidden = selected.size === 0;
            }
            if (selCount) {
                selCount.textContent = fmt(selected.size);
            }
        }

        function select(node, on) {
            const box = node.el.querySelector('.col-bulkactions input');
            if (box) {
                box.checked = on;
            }
            node.el.classList.toggle('is-selected', on);
            if (on) {
                selected.add(node.id);
            } else {
                selected.delete(node.id);
            }
        }

        // Bulk actions send only the top-most selected rows: the rest go with them.
        function topSelected() {
            return rows.filter(function (node) {
                return selected.has(node.id) && !ancestors(node).some(function (a) {
                    return selected.has(a.id);
                });
            }).map(function (node) {
                return node.id;
            });
        }

        function clearSelection() {
            Array.from(selected).forEach(function (id) {
                const node = byId.get(id);
                if (node) {
                    select(node, false);
                }
            });
            selected.clear();
            syncSel();
        }

        // --- Expiry control (drawer and Set expiry dialog) ------------------------------------
        // Chips set the day field. "Listings already posted" shows once the value differs from the
        // saved one, and goes back to Keep when it hides.
        function expiryDays(exp) {
            const field = exp.querySelector('.cat-exp-days');
            const v = parseInt(field ? field.value : '', 10);
            return Number.isNaN(v) ? null : v;
        }

        function syncPosted(exp, canShow) {
            const posted = exp.parentElement ? exp.parentElement.querySelector('.cat-exp-posted') : null;
            if (!posted) {
                return;
            }
            const days = expiryDays(exp);
            const show = canShow !== false && days !== null && days !== (+exp.dataset.saved || 0);
            if (!show && !posted.hidden) {
                const keep = posted.querySelector('input[value="keep"]');
                if (keep) {
                    keep.checked = true;
                }
            }
            posted.hidden = !show;
            const redate = posted.querySelector('[data-cat-redate]');
            if (redate) {
                redate.textContent = days === 0 ? I18N.redateNever : I18N.redateNew;
            }
        }

        function onExpiryInput(e) {
            const exp = e.target.closest('[data-cat-exp]');
            if (!exp) {
                return false;
            }
            const field = exp.querySelector('.cat-exp-days');
            if (e.target.type === 'radio' && field) {
                const v = e.target.value;
                field.hidden = v !== 'other';
                if (v === 'never') {
                    field.value = '0';
                } else if (v !== 'other') {
                    field.value = v;
                } else if (e.type === 'change') {
                    field.focus();
                }
            }
            syncPosted(exp, exp.dataset.posted !== 'off');
            return true;
        }

        // --- Drawer -------------------------------------------------------------------------
        let drawerFor = null;
        let drawerSeq = 0;

        const panel = drawer && typeof window.oscDrawer === 'function' ? window.oscDrawer({
            drawer: drawer,
            backdrop: backdrop,
            openOn: app,
            openClass: 'drawer-open',
            empty: false,
            focusFirst: function () {
                closeBtn.focus();
            },
            beforeClose: function () {
                drawerFor = null;
                drawerSeq++;
                rows.forEach(function (node) {
                    node.el.classList.remove('is-editing');
                });
            },
            afterClose: function () {
                drawerBody.replaceChildren();
            }
        }) : null;

        function closeDrawer() {
            if (panel) {
                panel.close();
            }
        }

        // opts: {id} edits a category, {parent} adds one under it, {name} prefills a new one.
        function openDrawer(opts) {
            if (!panel) {
                return;
            }
            const node = opts.id ? byId.get(opts.id) : null;
            const parent = node ? byId.get(node.parent) : byId.get(opts.parent || 0);
            rows.forEach(function (n) {
                n.el.classList.toggle('is-editing', n === node);
            });
            drawerTitle.textContent = node ? I18N.editTitle : (parent ? I18N.addSubTitle : I18N.newTitle);
            let sub = [];
            if (node) {
                sub = [
                    node.name,
                    parent ? oscFill(I18N.inside, [parent.name]) : '',
                    node.below ? plural('subcategoriesN', node.below) : '',
                    plural('listingsN', node.listings)
                ];
            } else if (parent) {
                sub = [oscFill(I18N.inside, [parent.name])];
            }
            drawerSub.textContent = sub.filter(Boolean).join(' · ');
            drawerSub.hidden = drawerSub.textContent === '';
            drawerFor = {id: node ? node.id : 0, parent: parent ? parent.id : 0};
            const seq = ++drawerSeq;
            drawerBody.replaceChildren(el('div', {class: 'cat-drawer-loading'}, [
                el('i', {class: 'bi bi-arrow-repeat', 'aria-hidden': 'true'})
            ]));
            panel.open(document.activeElement);

            const url = CFG.edit + '&id=' + (node ? node.id : 0) + (!node && parent ? '&parent_id=' + parent.id : '');
            request(url, undefined, true)
                .then(function (html) {
                    if (seq !== drawerSeq) {
                        return;
                    }
                    drawerBody.innerHTML = html;
                    if (typeof oscInitTabs === 'function') {
                        oscInitTabs(drawerBody);
                    }
                    setupForm(node, parent, opts.name || '');
                })
                .catch(function () {
                    if (seq === drawerSeq) {
                        flash('error', I18N.ajaxError);
                        closeDrawer();
                    }
                });
        }

        function setupForm(node, parent, name) {
            const select = drawerBody.querySelector('[data-cat-parent]');
            const hint = document.getElementById('cat-parent-hint');
            if (select) {
                const deep = fillParents(select, node ? node.id : 0, parent ? parent.id : 0);
                if (hint) {
                    const below = +hint.dataset.below || 0;
                    let text = '';
                    if (deep && I18N.depthNote) {
                        text = I18N.depthNote;
                    } else if (below > 0 && I18N.parentHint) {
                        text = plural('parentHint', below);
                    }
                    hint.textContent = text;
                    hint.hidden = text === '';
                }
            }
            const exp = drawerBody.querySelector('[data-cat-exp]');
            if (exp) {
                // A new category has no listings yet, so there is nothing to keep or re-date.
                exp.dataset.posted = node ? 'on' : 'off';
                syncPosted(exp, !!node);
            }
            syncApplyNote();
            const first = drawerBody.querySelector('input[type="text"], textarea');
            if (first && name) {
                first.value = name;
            }
            if (first) {
                first.focus();
            }
        }

        function syncApplyNote() {
            const box = drawerBody.querySelector('#apply_changes_to_subcategories');
            const note = document.getElementById('cat-apply-note');
            if (box && note) {
                note.textContent = box.checked ? I18N.applyOn : I18N.applyOff;
            }
        }

        function saveDrawer(form) {
            if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
                return;
            }
            const target = drawerFor;
            const submit = form.querySelector('[type="submit"]');
            if (submit) {
                submit.disabled = true;
            }
            const data = new FormData(form);
            const select = form.querySelector('[data-cat-parent]');
            if (select) {
                select.removeAttribute('aria-invalid');
            }
            post(form.getAttribute('action'), new URLSearchParams(data))
                .then(function (res) {
                    if (submit) {
                        submit.disabled = false;
                    }
                    if (!res) {
                        flash('error', I18N.ajaxError);
                        return;
                    }
                    if (res.error === 0 && res.created) {
                        reloadTo(res.id);
                        return;
                    }
                    if (res.error !== 0 && res.error !== 4) {
                        // The move may have gone through even though the fields were not saved.
                        const moved = res.moved && target ? byId.get(target.id) : null;
                        if (moved) {
                            relocate(moved.id, +res.parent_id || 0, Infinity);
                            openTo(moved);
                            if (select) {
                                select.value = String(+res.parent_id || 0);
                            }
                        }
                        if (res.field === '/parent_id' && select) {
                            select.setAttribute('aria-invalid', 'true');
                            select.focus();
                        }
                        flash('error', res.msg || I18N.ajaxError);
                        return;
                    }
                    const node = target ? byId.get(target.id) : null;
                    closeDrawer();
                    if (node) {
                        applySaved(node, res, data);
                    }
                    flash('ok', res.msg);
                })
                .catch(function () {
                    if (submit) {
                        submit.disabled = false;
                    }
                    flash('error', I18N.ajaxError);
                });
        }

        function applySaved(node, res, data) {
            if (res.text) {
                rename(node, String(res.text));
            }
            const days = parseInt(data.get('i_expiration_days'), 10);
            if (!Number.isNaN(days)) {
                const reach = data.get('apply_changes_to_subcategories') ? subtree(node) : [node];
                reach.forEach(function (n) {
                    n.expiry = days;
                    setExpiryCell(n);
                });
            }
            if (res.moved) {
                relocate(node.id, +res.parent_id || 0, Infinity);
                openTo(node);
            } else {
                syncParentTemplate();
                refilter();
            }
        }

        function reloadTo(id) {
            try {
                const u = new URL(window.location.href);
                u.hash = 'cat-' + id;
                window.history.replaceState(window.history.state, '', u.toString());
            } catch {
                // The hash only brings the row into view; the reload still shows it.
            }
            window.location.reload();
        }

        // Opens every parent of a row so it shows.
        function openTo(node) {
            ancestors(node).forEach(function (a) {
                a.open = true;
            });
            refilter();
        }

        // --- Dialogs, row actions, bulk bar, drag ---------------------------------------------
        const confirmDlg = document.getElementById('catConfirm');
        const moveDlg = document.getElementById('catMove');
        const expiryDlg = document.getElementById('catExpiry');

        function countsText(subs, items) {
            return subs > 0 ? oscFill(I18N.pair, [plural('subcategoriesN', subs), plural('listingsN', items)]) : plural('listingsN', items);
        }

        // Resolves true when the person confirms, false on Cancel, Esc or a click outside.
        function ask(title, text, label, danger) {
            return new Promise(function (resolve) {
                if (!confirmDlg || typeof confirmDlg.showModal !== 'function') {
                    resolve(window.confirm(title + '\n' + text));
                    return;
                }
                const ok = document.getElementById('catConfirmOk');
                let done = false;
                const finish = function (yes) {
                    if (!done) {
                        done = true;
                        ok.onclick = null;
                        resolve(yes);
                    }
                };
                document.getElementById('catConfirmTitle').textContent = title;
                document.getElementById('catConfirmText').textContent = text;
                ok.textContent = label;
                ok.classList.toggle('btn-danger', !!danger);
                ok.classList.toggle('btn-submit', !danger);
                confirmDlg.classList.toggle('osc-dialog-danger', !!danger);
                ok.onclick = function () {
                    confirmDlg.close();
                    finish(true);
                };
                confirmDlg.addEventListener('close', function onClose() {
                    // A late close event from the previous question must not cancel this one.
                    if (!confirmDlg.open) {
                        confirmDlg.removeEventListener('close', onClose);
                        finish(false);
                    }
                });
                confirmDlg.showModal();
                ok.focus();
            });
        }

        function failedText(res) {
            const msgs = res && res.failed ? Object.values(res.failed).filter(Boolean) : [];
            return Array.from(new Set(msgs)).join(' ');
        }

        function replyError(res) {
            return res && typeof res.error === 'string' && res.error ? res.error : I18N.ajaxError;
        }

        // The rows an enable or disable reached: the answer names them, else the whole subtree.
        function applyEnabled(node, on, res) {
            const ids = res && Array.isArray(res.affectedIds) ? res.affectedIds.map(function (a) {
                return +a.id;
            }) : [];
            const list = ids.length ? ids.map(function (id) {
                return byId.get(id);
            }).filter(Boolean) : subtree(node);
            list.forEach(function (n) {
                setEnabled(n, on);
            });
        }

        // After a move under a disabled parent the server switches the category off.
        function followMove(node, parentId, position) {
            relocate(node.id, parentId, position);
            const parent = byId.get(parentId);
            if (parent && !parent.enabled && node.enabled) {
                subtree(node).forEach(function (n) {
                    setEnabled(n, false);
                });
            }
            totals();
            openTo(node);
        }

        function toggle(node) {
            const on = !node.enabled;
            const run = function () {
                post(CFG.enable, new URLSearchParams({id: String(node.id), enabled: on ? '1' : '0'})).then(function (res) {
                    if (!res || res.error || !res.ok) {
                        flash('error', replyError(res));
                        return;
                    }
                    applyEnabled(node, on, res);
                    totals();
                    refilter();
                    announce(node.name + ': ' + (on ? I18N.enabled : I18N.disabled));
                }).catch(function () {
                    flash('error', I18N.ajaxError);
                });
            };
            const c = subtreeCounts(node);
            if (!on && (c.subs > 0 || c.items > 0)) {
                ask(oscFill(I18N.disableTitle, [node.name]), countsText(c.subs, c.items) + '. ' + I18N.disableFacts,
                    I18N.disable, false).then(function (yes) {
                    if (yes) {
                        run();
                    }
                });
            } else {
                run();
            }
        }

        // Deleting asks the server what would go first, so the confirm names the real counts.
        function confirmDelete(nodes) {
            const ids = nodes.map(function (n) {
                return n.id;
            });
            return request(CFG.preview + '&' + ids.map(function (id) {
                return 'ids[]=' + id;
            }).join('&')).then(function (pv) {
                // Without real counts the confirm would read "0 listings" before a delete that cannot be undone.
                if (!pv || pv.error || typeof pv.categories !== 'number' || typeof pv.listings !== 'number' || pv.categories < 1) {
                    flash('error', replyError(pv));
                    return null;
                }
                const title = oscFill(I18N.deleteConfirm, [nodes.length === 1 ? nodes[0].name : plural('categoriesN', nodes.length)]);
                let facts = I18N.deleteFacts;
                if (pv.categories <= nodes.length) {
                    facts = nodes.length === 1 ? I18N.deleteFactsOne : I18N.deleteFactsMany;
                }
                const text = [oscFill(I18N.pair, [plural('categoriesN', pv.categories), plural('listingsN', pv.listings)]) + '.',
                    facts, pv.queued ? I18N.deleteQueued : ''].filter(Boolean).join(' ');
                return ask(title, text, I18N.delete, true);
            });
        }

        function remove(node) {
            confirmDelete([node]).then(function (yes) {
                if (!yes) {
                    return null;
                }
                return post(CFG.del, new URLSearchParams({id: String(node.id)})).then(function (res) {
                    if (!res || res.error) {
                        flash('error', replyError(res));
                        return;
                    }
                    removeBlock(node.id);
                    if (res.queued) {
                        flash('ok', I18N.deleteQueued);
                    }
                    announce(I18N.delete + ': ' + node.name);
                });
            }).catch(function () {
                flash('error', I18N.ajaxError);
            });
        }

        // Move to…: the same parent list as the drawer, plus a position among the new siblings.
        const moveParent = document.getElementById('cat-move-parent');
        const movePos = document.getElementById('cat-move-pos');
        const moveHint = document.getElementById('cat-move-hint');
        const moveForm = document.getElementById('catMoveForm');
        let moving = [];
        let moveHere = '';

        function siblingsFor(parentId) {
            const parent = byId.get(parentId);
            return (parent ? parent.kids : roots).filter(function (n) {
                return moving.indexOf(n) === -1;
            });
        }

        // Position p = index among the siblings left once the moving ones are out: 0 is First,
        // the sibling count is Last, anything between reads "After" the sibling before it.
        function fillPositions(parentId, want) {
            const sibs = siblingsFor(parentId);
            const frag = document.createDocumentFragment();
            frag.append(el('option', {value: '0', text: I18N.first}));
            for (let i = 1; i < sibs.length; i++) {
                frag.append(el('option', {value: String(i), text: oscFill(I18N.after, [sibs[i - 1].name])}));
            }
            if (sibs.length > 0) {
                frag.append(el('option', {value: String(sibs.length), text: I18N.last}));
            }
            movePos.replaceChildren(frag);
            movePos.value = String(Math.min(want, sibs.length));
        }

        function openMove(nodes) {
            if (!moveDlg || typeof moveDlg.showModal !== 'function' || !nodes.length) {
                return;
            }
            moving = nodes;
            const first = nodes[0];
            const same = nodes.length === 1;
            const deep = fillParents(moveParent, nodes.map(function (n) {
                return n.id;
            }), same ? first.parent : 0);
            moveParent.removeAttribute('aria-invalid');
            const home = byId.get(first.parent);
            const group = home ? home.kids : roots;
            moveHere = same ? oscFill(I18N.nowIn, [home ? home.name : I18N.topLevel, group.indexOf(first) + 1, group.length]) : '';
            moveHint.textContent = deep && I18N.depthNote ? I18N.depthNote : moveHere;
            const sibs = siblingsFor(same ? first.parent : 0);
            fillPositions(same ? first.parent : 0, same ? group.indexOf(first) : sibs.length);
            document.getElementById('catMoveTitle').textContent = oscFill(I18N.moveOne, [same ? first.name : plural('categoriesN', nodes.length)]);
            moveDlg.showModal();
            moveParent.focus();
        }

        function submitMove() {
            const parentId = +moveParent.value || 0;
            const position = +movePos.value || 0;
            const submit = moveForm.querySelector('[type="submit"]');
            const list = moving.slice();
            if (submit) {
                submit.disabled = true;
            }
            const body = new URLSearchParams();
            if (list.length === 1) {
                body.set('id', String(list[0].id));
            } else {
                body.set('do', 'move');
                list.forEach(function (n) {
                    body.append('ids[]', String(n.id));
                });
            }
            body.set('parent_id', String(parentId));
            body.set('position', String(position));
            post(list.length === 1 ? CFG.move : CFG.bulk, body).then(function (res) {
                if (submit) {
                    submit.disabled = false;
                }
                if (!res || res.error || !res.ok) {
                    moveParent.setAttribute('aria-invalid', 'true');
                    moveHint.textContent = replyError(res);
                    return;
                }
                const done = list.length === 1 ? [list[0].id] : (res.done || []);
                done.forEach(function (id, i) {
                    const node = byId.get(+id);
                    if (node) {
                        followMove(node, parentId, position + i);
                    }
                });
                moveDlg.close();
                if (list.length > 1) {
                    clearSelection();
                }
                const bad = failedText(res);
                flash(bad ? 'error' : 'ok', bad || res.message || I18N.moved);
                announce(I18N.moved);
            }).catch(function () {
                if (submit) {
                    submit.disabled = false;
                }
                moveHint.textContent = I18N.ajaxError;
            });
        }

        // Set expiry… for the selection: the drawer's expiry control, with the posted-listings
        // choice shown only when the selection holds listings.
        function openExpiry(nodes) {
            if (!expiryDlg || typeof expiryDlg.showModal !== 'function' || !nodes.length) {
                return;
            }
            moving = nodes;
            const exp = expiryDlg.querySelector('[data-cat-exp]');
            const field = expiryDlg.querySelector('.cat-exp-days');
            const never = expiryDlg.querySelector('input[value="never"]');
            if (never) {
                never.checked = true;
            }
            if (field) {
                field.value = '0';
                field.hidden = true;
            }
            const keep = expiryDlg.querySelector('input[name="expiry_listings"][value="keep"]');
            if (keep) {
                keep.checked = true;
            }
            const items = nodes.reduce(function (sum, n) {
                return sum + n.listings;
            }, 0);
            const below = nodes.reduce(function (sum, n) {
                return sum + n.below;
            }, 0);
            if (exp) {
                exp.dataset.saved = '-1';
                exp.dataset.posted = items > 0 ? 'on' : 'off';
                syncPosted(exp, items > 0);
            }
            const sub = document.getElementById('cat-bx-sub');
            sub.checked = false;
            sub.closest('.form-check').hidden = below === 0;
            document.getElementById('cat-bx-sub-label').textContent = plural('applyLabel', below);
            expiryDlg.showModal();
        }

        function submitExpiry(form) {
            const list = moving.slice();
            const exp = form.querySelector('[data-cat-exp]');
            const field = form.querySelector('.cat-exp-days');
            const days = expiryDays(exp);
            if (days === null || days < 0 || days > 999) {
                field.hidden = false;
                field.focus();
                return;
            }
            const posted = form.querySelector('.cat-exp-posted');
            const keep = !posted || posted.hidden;
            const pick = form.querySelector('input[name="expiry_listings"]:checked');
            const toSub = form.querySelector('#cat-bx-sub').checked;
            const body = new URLSearchParams();
            body.set('do', 'expiry');
            list.forEach(function (n) {
                body.append('ids[]', String(n.id));
            });
            body.set('days', String(days));
            body.set('expiry_listings', keep || !pick ? 'keep' : pick.value);
            if (toSub) {
                body.set('to_subcategories', '1');
            }
            post(CFG.bulk, body).then(function (res) {
                if (!res || res.error || !res.ok) {
                    flash('error', replyError(res));
                    return;
                }
                const ids = (res.done || []).concat(res.queued || []);
                ids.forEach(function (id) {
                    const node = byId.get(+id);
                    if (node) {
                        (toSub ? subtree(node) : [node]).forEach(function (n) {
                            n.expiry = days;
                            setExpiryCell(n);
                        });
                    }
                });
                expiryDlg.close();
                clearSelection();
                flash('ok', res.message);
            }).catch(function () {
                flash('error', I18N.ajaxError);
            });
        }

        // The bulk bar: only the top-most selected rows go to the server, the rest ride along.
        function runBulk(what) {
            const ids = topSelected();
            const nodes = ids.map(function (id) {
                return byId.get(id);
            });
            if (!nodes.length) {
                return;
            }
            if (what === 'move') {
                openMove(nodes);
                return;
            }
            if (what === 'expiry') {
                openExpiry(nodes);
                return;
            }
            const finish = function (res) {
                if (!res || res.error || !res.ok) {
                    flash('error', replyError(res));
                    return;
                }
                const bad = failedText(res);
                const reached = (res.done || []).concat(res.queued || []);
                reached.forEach(function (id) {
                    const node = byId.get(+id);
                    if (!node) {
                        return;
                    }
                    if (what === 'delete') {
                        removeBlock(node.id);
                    } else {
                        subtree(node).forEach(function (n) {
                            setEnabled(n, what === 'enable');
                        });
                    }
                });
                clearSelection();
                totals();
                refilter();
                flash(bad ? 'error' : 'ok', bad || res.message);
            };
            const run = function () {
                const body = new URLSearchParams();
                body.set('do', what);
                ids.forEach(function (id) {
                    body.append('ids[]', String(id));
                });
                return post(CFG.bulk, body).then(finish).catch(function () {
                    flash('error', I18N.ajaxError);
                });
            };
            if (what === 'delete') {
                confirmDelete(nodes).then(function (yes) {
                    return yes ? run() : null;
                });
            } else if (what === 'disable') {
                const subs = nodes.reduce(function (sum, n) {
                    return sum + n.below;
                }, 0);
                const items = nodes.reduce(function (sum, n) {
                    return sum + n.listings;
                }, 0);
                if (subs > 0 || items > 0) {
                    ask(oscFill(I18N.disableTitle, [nodes.length === 1 ? nodes[0].name : plural('categoriesN', nodes.length)]),
                        countsText(subs, items) + '. ' + I18N.disableFacts, I18N.disable, false).then(function (yes) {
                        return yes ? run() : null;
                    });
                } else {
                    run();
                }
            } else {
                run();
            }
        }

        const bulkForm = document.getElementById('cat-bulk-form');
        const bulkSelect = document.getElementById('cat-bulk');
        if (bulkForm && bulkSelect) {
            bulkForm.addEventListener('submit', function (e) {
                e.preventDefault();
                if (bulkSelect.value) {
                    runBulk(bulkSelect.value);
                }
            });
        }
        if (moveForm) {
            moveForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitMove();
            });
            moveParent.addEventListener('change', function () {
                const same = moving.length === 1 && moving[0].parent === (+moveParent.value || 0);
                const parentId = +moveParent.value || 0;
                const list = same ? (byId.get(parentId) ? byId.get(parentId).kids : roots) : null;
                fillPositions(parentId, same ? list.indexOf(moving[0]) : Infinity);
                moveParent.removeAttribute('aria-invalid');
                moveHint.textContent = moveHere;
            });
        }
        const expiryForm = document.getElementById('catExpiryForm');
        if (expiryForm) {
            expiryForm.addEventListener('submit', function (e) {
                e.preventDefault();
                submitExpiry(expiryForm);
            });
            expiryForm.addEventListener('input', onExpiryInput);
            expiryForm.addEventListener('change', onExpiryInput);
        }

        // Dragging keeps a category inside its sibling group. Everything deeper folds away while
        // it moves, so the only rows left to cross are siblings. A refused save puts it back.
        const sorter = tbody && typeof window.osc === 'object' && typeof window.osc.sortable === 'function'
            ? window.osc.sortable(tbody, {
                handle: '.cat-handle',
                draggable: 'tr.cat-row',
                onStart: function (evt) {
                    const node = byId.get(+evt.item.dataset.id);
                    if (!node) {
                        return;
                    }
                    node.open = false;
                    rows.forEach(function (n) {
                        if (n.shown && n.depth > node.depth) {
                            n.el.hidden = true;
                        }
                    });
                },
                onMove: function (evt) {
                    const node = byId.get(+evt.dragged.dataset.id);
                    const rel = evt.related;
                    return !!node && rel.classList.contains('cat-row') && (+rel.dataset.parent || 0) === node.parent;
                },
                onEnd: function (evt) {
                    const node = byId.get(+evt.item.dataset.id);
                    if (!node) {
                        refilter();
                        return;
                    }
                    let idx = 0;
                    for (let p = evt.item.previousElementSibling; p; p = p.previousElementSibling) {
                        if (!p.classList.contains('cat-row')) {
                            continue;
                        }
                        if ((+p.dataset.parent || 0) === node.parent) {
                            idx++;
                        } else if (+p.dataset.id === node.parent) {
                            break;
                        }
                    }
                    const list = node.parent ? byId.get(node.parent).kids : roots;
                    const was = list.indexOf(node);
                    relocate(node.id, node.parent, idx);
                    if (idx === was) {
                        return;
                    }
                    post(CFG.move, new URLSearchParams({id: String(node.id), parent_id: String(node.parent), position: String(idx)}))
                        .then(function (res) {
                            if (!res || res.error || !res.ok) {
                                relocate(node.id, node.parent, was);
                                flash('error', replyError(res));
                            }
                        })
                        .catch(function () {
                            relocate(node.id, node.parent, was);
                            flash('error', I18N.ajaxError);
                        });
                }
            })
            : null;
        if (sorter) {
            sorter.option('disabled', filtering());
            app.addEventListener('cat:filtered', function () {
                sorter.option('disabled', filtering());
            });
        }

        // --- Events -------------------------------------------------------------------------
        // Row actions by data-cat-act.
        const actions = {
            edit: function (node) {
                openDrawer({id: node.id});
            },
            addsub: function (node) {
                openDrawer({parent: node.id});
            },
            move: function (node) {
                openMove([node]);
            },
            toggle: toggle,
            delete: remove
        };

        app.addEventListener('click', function (e) {
            const disc = e.target.closest('.cat-disclosure');
            if (disc && tbody && tbody.contains(disc)) {
                if (disc.getAttribute('aria-disabled') === 'true') {
                    return;
                }
                const node = byId.get(+disc.closest('tr').dataset.id);
                if (node) {
                    node.open = !node.open;
                    refilter();
                }
                return;
            }
            const tab = e.target.closest('.cat-tabs a');
            if (tab && e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey) {
                e.preventDefault();
                setTab(tabOf(tab));
                return;
            }
            const btn = e.target.closest('[data-cat-act]');
            if (!btn || btn.disabled) {
                return;
            }
            const act = btn.dataset.catAct;
            if (act === 'expand-all' || act === 'collapse-all') {
                rows.forEach(function (node) {
                    node.open = act === 'expand-all' && node.kids.length > 0;
                });
                refilter();
                return;
            }
            if (act === 'clear-selection') {
                clearSelection();
                return;
            }
            if (act === 'clear-search') {
                clearSearch(true);
                return;
            }
            if (act === 'show-all') {
                clearSearch(true);
                setTab('');
                return;
            }
            if (act === 'add-from-search') {
                openDrawer({name: state.raw});
                return;
            }
            const tr = btn.closest('tr.cat-row');
            const node = tr ? byId.get(+tr.dataset.id) : null;
            if (node && actions[act]) {
                e.preventDefault();
                actions[act](node, btn);
            }
        });

        if (tbody) {
            tbody.addEventListener('change', function (e) {
                if (!e.target.matches('.col-bulkactions input')) {
                    return;
                }
                const node = byId.get(+e.target.closest('tr').dataset.id);
                if (node) {
                    select(node, e.target.checked);
                    syncSel();
                }
            });
        }

        if (checkAll) {
            checkAll.addEventListener('change', function () {
                const on = checkAll.checked;
                rows.forEach(function (node) {
                    if (node.shown) {
                        select(node, on);
                    }
                });
                syncSel();
            });
        }

        if (search) {
            let timer = 0;
            search.addEventListener('input', function () {
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    state.raw = search.value.trim();
                    state.q = norm(state.raw);
                    refilter();
                }, 150);
            });
            search.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && search.value !== '') {
                    e.preventDefault();
                    window.clearTimeout(timer);
                    clearSearch(true);
                }
            });
        }

        // The page-head and empty-state buttons sit outside the app.
        document.addEventListener('click', function (e) {
            if (e.target.closest('.js-cat-add')) {
                e.preventDefault();
                openDrawer({});
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', closeDrawer);
        }

        if (drawerBody) {
            drawerBody.addEventListener('click', function (e) {
                if (e.target.closest('[data-cat-drawer-cancel]')) {
                    e.preventDefault();
                    closeDrawer();
                }
            });
            drawerBody.addEventListener('input', onExpiryInput);
            drawerBody.addEventListener('change', function (e) {
                if (!onExpiryInput(e) && e.target.id === 'apply_changes_to_subcategories') {
                    syncApplyNote();
                }
            });
            drawerBody.addEventListener('submit', function (e) {
                const form = e.target.closest('[data-cat-edit-form]');
                if (form) {
                    e.preventDefault();
                    saveDrawer(form);
                }
            });
        }

        // --- Start --------------------------------------------------------------------------
        readRows();
        if (state.tab) {
            setTab(state.tab);
        }
        const hash = /^#cat-(\d+)$/.exec(window.location.hash);
        const target = hash ? byId.get(+hash[1]) : null;
        if (target) {
            openTo(target);
            app.addEventListener('cat:filtered', function () {
                target.el.scrollIntoView({block: 'center'});
                target.el.classList.add('is-flash');
                window.setTimeout(function () {
                    target.el.classList.remove('is-flash');
                }, 1700);
            }, {once: true});
        }
        if (rows.length) {
            refilter();
        }
        app.dataset.ready = '1';
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
