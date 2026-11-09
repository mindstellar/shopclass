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
            expiryPreview: app.dataset.expiryPreviewUrl || '',
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
            return oscFill(window.osc.plural(I18N[key], n), [fmt(n)]);
        }

        // The same with numbered placeholders: values fill %1$s, %2$d... in order.
        function pluralWith(key, n, values) {
            return oscFill(window.osc.plural(I18N[key], n), values);
        }

        const tbody = document.getElementById('cat-rows');
        const table = tbody ? tbody.closest('table') : null;
        const checkAll = document.getElementById('cat-check-all');
        const search = document.getElementById('cat-q');
        const filterLine = document.getElementById('cat-filter-line');
        const announcer = document.getElementById('cat-announce');
        const keyHelp = document.getElementById('cat-key-help');
        const keyHelpText = keyHelp ? keyHelp.textContent : '';
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
            window.osc.announce(announcer, text);
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
                    price: d.price === '1',
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
            splitRows();
        }

        // Adding or removing a row restyles every row in its tbody, so long lists are cut into
        // tbodies of PART rows: a move then restyles a few dozen rows, not all of them.
        const PART = 40;

        function splitRows() {
            const list = Array.from(tbody.children);
            let part = tbody;
            for (let i = PART; i < list.length; i += PART) {
                const next = el('tbody', {class: 'cat-rows-part'});
                part.after(next);
                next.append(...list.slice(i, i + PART));
                part = next;
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

        // The phone's meta line: subcategories, listings, and the expiry when one is set.
        function setMeta(node) {
            const p = node.el.querySelector('.cat-meta');
            if (!p) {
                return;
            }
            const parts = [];
            if (node.subs > 0) {
                parts.push(plural('subcategoriesN', node.subs));
            }
            parts.push(node.listings > 0 ? plural('listingsN', node.listings) : I18N.noListings);
            if (node.expiry > 0) {
                parts.push(oscFill(I18N.expiryTag, [node.expiry]));
            }
            p.textContent = parts.join(' · ');
        }

        function setExpiryCell(node) {
            node.el.dataset.expiry = node.expiry;
            setMeta(node);
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

        // Below the top level the name and handle are described as "Inside {parent}": shared words
        // around the parent's name button, so a rename needs nothing here.
        function setInside(node) {
            const inside = node.parent ? 'cat-i1 cat-n-' + node.parent + ' cat-i2' : '';
            if (inside) {
                node.nameEl.setAttribute('aria-describedby', inside);
            } else {
                node.nameEl.removeAttribute('aria-describedby');
            }
            const handle = node.el.querySelector('.cat-handle');
            if (handle) {
                handle.setAttribute('aria-describedby', (inside ? inside + ' ' : '') + 'cat-key-help');
            }
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
                    setInside(node);
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
                    setMeta(node);
                }
                if (+d.listings !== node.listings) {
                    d.listings = node.listings;
                    setNum(node.el.querySelector('.col-listings'), node.listings, false);
                    setMeta(node);
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
            const anchor = next ? next.el : stateRow();
            rows.slice(at, at + size).forEach(function (n) {
                anchor.before(n.el);
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
                } else if (!window.osc.flatTree.fits(h - 1, d, CFG.limit) && d + h > today) {
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
        let spokenFilter = '';

        function filtering() {
            return state.q !== '' || state.tab !== '';
        }

        function stateRow() {
            if (!noResult) {
                noResult = el('tr', {class: 'cat-state', hidden: ''}, [el('td', {colspan: '6'})]);
                table.tBodies[table.tBodies.length - 1].append(noResult);
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

        // Runs fn once the pending filter pass has shown or hidden the rows.
        function afterFilter(fn) {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(fn);
            });
        }

        function runFilter() {
            frame = 0;
            const t0 = performance.now();
            const focusRow = document.activeElement && tbody ? document.activeElement.closest('tr.cat-row') : null;
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
                // Every handle is described by this line, so it gives the reason while they are off.
                if (keyHelp) {
                    keyHelp.textContent = f ? I18N.filterReorder : keyHelpText;
                }
            }
            drawState(f, matches);
            drawFilterLine(f, matches);
            syncSel();
            if (focusRow && focusRow.hidden) {
                rescueFocus(byId.get(+focusRow.dataset.id));
            }
            const ms = performance.now() - t0;
            app.dispatchEvent(new CustomEvent('cat:filtered', {detail: {shown: shown, matches: matches, ms: ms}}));
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
            const tabName = state.tab === 'on' ? I18N.enabled : I18N.disabled;
            if (state.q) {
                const title = state.tab ? oscFill(I18N.noMatchIn, [state.raw, tabName]) : oscFill(I18N.noMatch, [state.raw]);
                td.replaceChildren(
                    el('p', {}, [el('strong', {text: title})]),
                    el('p', {class: 'cat-state-hint', text: I18N.noMatchHint}),
                    el('div', {class: 'd-flex flex-wrap gap-2'}, [
                        el('button', {type: 'button', class: 'btn btn-dim btn-sm', 'data-cat-act': 'clear-search', text: I18N.clearSearch}),
                        el('button', {type: 'button', class: 'btn btn-dim btn-sm', 'data-cat-act': 'add-from-search', text: oscFill(I18N.addAs, [state.raw])})
                    ])
                );
            } else {
                td.replaceChildren(
                    el('p', {}, [el('strong', {text: state.tab === 'on' ? I18N.emptyOn : I18N.emptyOff})]),
                    el('p', {class: 'cat-state-hint', text: state.tab === 'on' ? I18N.allDisabled : I18N.allShowing})
                );
            }
        }

        // The line is spoken only when the search or tab changed, so it never talks over the result of an action.
        function drawFilterLine(f, matches) {
            if (!filterLine) {
                return;
            }
            const key = f ? state.q + '|' + state.tab : '';
            const speak = key !== spokenFilter;
            spokenFilter = key;
            if (!f || matches === 0) {
                filterLine.hidden = true;
                if (f && state.q && speak) {
                    announce(oscFill(I18N.noMatch, [state.raw]));
                }
                return;
            }
            const lead = state.q
                ? oscFill(window.osc.plural(I18N.filterMatch, matches), [fmt(matches), state.raw])
                : plural(state.tab === 'on' ? 'filterTabOn' : 'filterTabOff', matches);
            const text = lead + ' ' + I18N.filterGrey;
            filterLine.replaceChildren(
                el('span', {text: text}),
                el('button', {type: 'button', class: 'btn-link', 'data-cat-act': 'show-all', text: I18N.showAll})
            );
            filterLine.hidden = false;
            if (speak) {
                announce(text);
            }
        }

        // Focus on a row that has gone (deleted, or hidden by a filter) moves to the next shown
        // row's name, or to the search box after the last row.
        function nextShown(node, skip) {
            const at = node ? rows.indexOf(node) : -1;
            const ok = function (n) {
                return n.shown && !n.el.hidden && n.el.isConnected && !(skip && skip.has(n.id));
            };
            if (at === -1) {
                return null;
            }
            for (let i = at + 1; i < rows.length; i++) {
                if (ok(rows[i])) {
                    return rows[i];
                }
            }
            return null;
        }

        function rescueFocus(node) {
            const next = nextShown(node, null);
            if (next) {
                next.nameEl.focus();
            } else if (search) {
                search.focus();
            }
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
            const hint = posted.querySelector('[data-cat-redate-hint]');
            if (hint) {
                hint.dataset.plain = hint.dataset.plain || hint.textContent;
                hint.textContent = days === 0 ? I18N.redateHintNever : (days > 0 ? plural('redateHintDays', days) : hint.dataset.plain);
            }
        }

        function onExpiryInput(e) {
            const exp = e.target.closest('[data-cat-exp]');
            if (!exp) {
                // Keep or new end dates: the live line follows the choice.
                const posted = e.target.closest('.cat-exp-posted');
                const owner = posted && posted.parentElement ? posted.parentElement.querySelector('[data-cat-exp]') : null;
                if (owner) {
                    refreshEndNow(owner);
                }
                return !!owner;
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
            refreshEndNow(exp);
            return true;
        }

        // --- Drawer -------------------------------------------------------------------------
        let drawerFor = null;
        let drawerSeq = 0;
        let drawerOpener = null;

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
            drawerOpener = opts.opener || document.activeElement;
            panel.open(drawerOpener);

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
            // The name and description labels come without a for, so they are tied to their fields here.
            drawerBody.querySelectorAll('.form-controls > label:not([for])').forEach(function (label) {
                const field = label.nextElementSibling;
                if (field && field.id && field.matches('input, textarea, select')) {
                    label.htmlFor = field.id;
                }
            });
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

        // The note under "Also apply to its N subcategories" counts the subcategories set differently from the drawer now.
        function syncApplyNote() {
            const box = drawerBody.querySelector('#apply_changes_to_subcategories');
            const note = document.getElementById('cat-apply-note');
            if (!box || !note) {
                return;
            }
            const node = drawerFor && drawerFor.id ? byId.get(drawerFor.id) : null;
            const exp = drawerBody.querySelector('[data-cat-exp]');
            const priceBox = drawerBody.querySelector('#b_price_enabled');
            const days = exp ? expiryDays(exp) : null;
            const differ = node ? subtree(node).slice(1).filter(function (n) {
                return n.expiry !== (days === null ? node.expiry : days) || (priceBox ? n.price !== priceBox.checked : false);
            }).length : 0;
            note.textContent = box.checked
                ? I18N.applyOn + ' ' + (differ ? plural('applyChange', differ) : I18N.applyNoChange)
                : I18N.applyOff + ' ' + (differ ? plural('applyDiffer', differ) : I18N.applyMatch);
            box.setAttribute('aria-describedby', note.id);
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
                    const opener = drawerOpener;
                    closeDrawer();
                    if (node) {
                        applySaved(node, res, data);
                        if (res.moved) {
                            // The move takes the row out of the page for a moment, and focus with it.
                            afterFilter(function () {
                                backTo(opener, node);
                            });
                        }
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
            const reach = data.get('apply_changes_to_subcategories') ? subtree(node) : [node];
            reach.forEach(function (n) {
                n.price = !!data.get('b_price_enabled');
                n.el.dataset.price = n.price ? '1' : '0';
                if (!Number.isNaN(days)) {
                    n.expiry = days;
                    setExpiryCell(n);
                }
            });
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

        // "A, B and C" in the page language.
        function listText(parts) {
            try {
                return new Intl.ListFormat(document.documentElement.lang || undefined, {style: 'long', type: 'conjunction'}).format(parts);
            } catch {
                return parts.join(', ');
            }
        }

        // Puts focus back on the control a dialog came from, or on the row's name when that is gone or hidden.
        function backTo(opener, node) {
            const name = node && node.el && node.el.isConnected ? node.el.querySelector('.cat-name') : null;
            if (opener && opener.isConnected && typeof opener.focus === 'function') {
                opener.focus();
            }
            if (name && document.activeElement !== opener) {
                name.focus();
            }
        }

        const FACT_ICON = {bad: 'bi-exclamation-triangle', ok: 'bi-check-circle'};

        // <li> rows for a dialog's facts: [kind, text], kind '' (a dot), 'bad' or 'ok'.
        function factNodes(facts) {
            return facts.map(function (f) {
                const icon = FACT_ICON[f[0]];
                return el('li', f[0] ? {class: 'is-' + f[0]} : {}, [
                    icon ? el('i', {class: 'bi ' + icon, 'aria-hidden': 'true'}) : el('span', {class: 'cat-fact-dot', 'aria-hidden': 'true'}),
                    el('span', {text: f[1]})
                ]);
            });
        }

        // o = {title, facts: [[kind, text]], ok, danger, aside: {text, label, run}|null, opener, node}.
        // Resolves true when the person confirms, false on Cancel, Esc or a click outside. Cancel has focus first.
        function ask(o) {
            return new Promise(function (resolve) {
                if (!confirmDlg || typeof confirmDlg.showModal !== 'function') {
                    resolve(window.confirm([o.title].concat(o.facts.map(function (f) {
                        return f[1];
                    })).join('\n')));
                    return;
                }
                const ok = document.getElementById('catConfirmOk');
                const cancel = document.getElementById('catConfirmCancel');
                const asideBox = document.getElementById('catConfirmAside');
                const asideBtn = asideBox.querySelector('button');
                let done = false;
                let jumped = false;
                const finish = function (yes) {
                    if (!done) {
                        done = true;
                        ok.onclick = null;
                        cancel.onclick = null;
                        asideBtn.onclick = null;
                        resolve(yes);
                        if (!jumped) {
                            backTo(o.opener, o.node);
                        }
                    }
                };
                document.getElementById('catConfirmTitle').textContent = o.title;
                document.getElementById('catConfirmFacts').replaceChildren(...factNodes(o.facts));
                asideBox.hidden = !o.aside;
                if (o.aside) {
                    asideBox.querySelector('p').textContent = o.aside.text;
                    asideBtn.textContent = o.aside.label;
                    asideBtn.onclick = function () {
                        jumped = true;
                        confirmDlg.close();
                        finish(false);
                        o.aside.run();
                    };
                }
                ok.textContent = o.ok;
                ok.classList.toggle('btn-danger', !!o.danger);
                ok.classList.toggle('btn-submit', !o.danger);
                confirmDlg.classList.toggle('osc-dialog-danger', !!o.danger);
                ok.onclick = function () {
                    confirmDlg.close();
                    finish(true);
                };
                cancel.onclick = function () {
                    confirmDlg.close();
                    finish(false);
                };
                confirmDlg.addEventListener('close', function onClose() {
                    // A late close event from the previous question must not cancel this one.
                    if (!confirmDlg.open) {
                        confirmDlg.removeEventListener('close', onClose);
                        finish(false);
                    }
                });
                confirmDlg.showModal();
                cancel.focus();
            });
        }

        const catsIn = function (nodes) {
            return nodes.reduce(function (sum, n) {
                return sum + 1 + n.below;
            }, 0);
        };

        const listingsIn = function (nodes) {
            return nodes.reduce(function (sum, n) {
                return sum + n.listings;
            }, 0);
        };

        // Selected rows a bulk question stands for: a selected row inside another is counted once.
        function pickedCount(nodes, bulk) {
            if (!bulk) {
                return nodes.length;
            }
            const inside = new Set();
            nodes.forEach(function (n) {
                subtree(n).forEach(function (s) {
                    inside.add(s.id);
                });
            });
            let c = 0;
            selected.forEach(function (id) {
                c += inside.has(id) ? 1 : 0;
            });
            return Math.max(nodes.length, c);
        }

        function overlapNote(nodes, bulk) {
            if (!bulk) {
                return '';
            }
            const tops = new Set(nodes.map(function (n) {
                return n.id;
            }));
            const inner = [];
            selected.forEach(function (id) {
                const n = byId.get(id);
                const host = n && !tops.has(id) ? ancestors(n).find(function (a) {
                    return tops.has(a.id);
                }) : null;
                if (host) {
                    inner.push([n, host]);
                }
            });
            if (!inner.length) {
                return '';
            }
            return inner.length === 1 ? oscFill(I18N.overlapOne, [inner[0][0].name, inner[0][1].name]) : plural('overlapMany', inner.length);
        }

        // The question before a disable that reaches subcategories or listings; true at once when it reaches neither.
        function confirmDisable(nodes, bulk, opener) {
            const cats = catsIn(nodes);
            const subs = cats - nodes.length;
            const lst = listingsIn(nodes);
            if (!subs && !lst) {
                return Promise.resolve(true);
            }
            const picked = pickedCount(nodes, bulk);
            const one = nodes.length === 1 && picked === 1;
            const facts = [];
            const inside = overlapNote(nodes, bulk);
            if (inside) {
                facts.push(['', inside]);
            }
            if (subs) {
                facts.push(['', one ? plural('disableSubs', subs) : plural('withSubs', cats)]);
            }
            facts.push(['', lst ? plural('disableListings', lst) : I18N.disableNoListings]);
            facts.push(['ok', I18N.disableSafe]);
            return ask({
                title: one ? oscFill(I18N.disableTitle, [nodes[0].name]) : plural('disableSelTitle', picked),
                facts: facts,
                ok: cats > 1 ? plural('disableButton', cats) : I18N.disable,
                danger: false,
                opener: opener,
                node: nodes[0]
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
            return list;
        }

        // "Disabled Vehicles and 7 subcategories." / "Enabled Cars. It stays hidden until Vehicles is enabled."
        function stateSentence(node, on, cats) {
            const subs = cats - 1;
            let text;
            if (on) {
                text = subs > 0 ? pluralWith('enabledSubs', subs, [node.name, fmt(subs)]) : oscFill(I18N.enabledOne, [node.name]);
                const off = ancestors(node).find(function (a) {
                    return !a.enabled;
                });
                return off ? text + ' ' + oscFill(I18N.stillHidden, [off.name]) : text;
            }
            return subs > 0 ? pluralWith('disabledSubs', subs, [node.name, fmt(subs)]) : oscFill(I18N.disabledOne, [node.name]);
        }

        // A toast with Undo, spoken too. Without the toast layer the flash bar stands in.
        function notify(text, undo) {
            announce(text);
            if (typeof window.osc.toast === 'function') {
                window.osc.toast(text, undo ? {undo: undo} : {});
            } else {
                flash('ok', text);
            }
        }

        // A request that must answer ok; otherwise it throws {reply: text} for the caller to flash.
        function must(url, params) {
            return post(url, params).then(function (res) {
                if (!res || res.error || !res.ok) {
                    throw {reply: replyError(res)};
                }
                return res;
            });
        }

        function undoFailed(e) {
            flash('error', e && e.reply ? e.reply : I18N.ajaxError);
        }

        // The on or off state of these rows and everything below them.
        function snapStates(nodes) {
            const map = new Map();
            nodes.forEach(function (top) {
                subtree(top).forEach(function (n) {
                    map.set(n.id, n.enabled);
                });
            });
            return map;
        }

        // Puts the rows under tops back to the wanted states. Enable and disable reach every level
        // below, so a row is only changed when it differs from what its parent's change left it as.
        function restoreStates(tops, want) {
            const sim = snapStates(tops);
            const steps = [];
            tops.forEach(function (top) {
                const walk = function (n, isTop) {
                    const on = want.get(n.id);
                    if (on !== undefined && on !== sim.get(n.id)) {
                        subtree(n).forEach(function (s) {
                            sim.set(s.id, on);
                        });
                        const last = steps[steps.length - 1];
                        if (isTop) {
                            steps.push({one: n.id, on: on});
                        } else if (last && !last.one && last.on === on) {
                            last.ids.push(n.id);
                        } else {
                            steps.push({ids: [n.id], on: on});
                        }
                    }
                    n.kids.forEach(function (k) {
                        walk(k, false);
                    });
                };
                walk(top, true);
            });
            return steps.reduce(function (chain, step) {
                return chain.then(function () {
                    if (step.one) {
                        return must(CFG.enable, new URLSearchParams({id: String(step.one), enabled: step.on ? '1' : '0'}));
                    }
                    const body = new URLSearchParams();
                    body.set('do', step.on ? 'enable' : 'disable');
                    step.ids.forEach(function (id) {
                        body.append('ids[]', String(id));
                    });
                    return must(CFG.bulk, body);
                });
            }, Promise.resolve()).then(function () {
                want.forEach(function (on, id) {
                    const n = byId.get(id);
                    if (n && n.enabled !== on) {
                        setEnabled(n, on);
                    }
                });
                totals();
                refilter();
            });
        }

        // Switches an enabled block off locally, as the server does after a move under a disabled parent.
        function offUnder(node) {
            const parent = byId.get(node.parent);
            if (parent && !parent.enabled && node.enabled) {
                subtree(node).forEach(function (n) {
                    setEnabled(n, false);
                });
            }
        }

        // Where a category sits now, for putting it back.
        function spot(node) {
            const list = node.parent ? byId.get(node.parent).kids : roots;
            return {node: node, parent: node.parent, index: list.indexOf(node), states: snapStates([node])};
        }

        // Quiet "Inside X" tag, tint and flash on a row that just landed.
        function markLanded(node) {
            const row = node.el;
            const handle = row.querySelector('.cat-handle');
            row.classList.remove('is-tree-flash');
            void row.offsetWidth;
            row.classList.add('is-tree-flash');
            window.setTimeout(function () {
                row.classList.remove('is-tree-flash');
            }, 1700);
            const parent = byId.get(node.parent);
            const tag = el('span', {class: 'tree-place-tag', 'aria-hidden': 'true', text: parent ? oscFill(I18N.inside, [parent.name]) : I18N.topLevel});
            (handle ? handle.parentNode : row).appendChild(tag);
            row.classList.add('is-tree-placed');
            if (parent) {
                parent.el.classList.add('is-drop-parent');
            }
            window.setTimeout(function () {
                tag.remove();
                row.classList.remove('is-tree-placed');
                if (parent) {
                    parent.el.classList.remove('is-drop-parent');
                }
            }, 1600);
        }

        // Runs a change that moves rows with the rows sliding to their new places.
        function sliding(change) {
            if (sorter) {
                sorter.flip(change);
            } else {
                change();
            }
        }

        // Undo of moves: each category goes back to its old parent and position, lowest position
        // first, then the on or off states it had come back as.
        function undoMoves(entries) {
            const order = entries.slice().sort(function (a, b) {
                return a.index - b.index;
            });
            return order.reduce(function (chain, e) {
                return chain.then(function () {
                    return must(CFG.move, new URLSearchParams({id: String(e.node.id), parent_id: String(e.parent), position: String(e.index)}))
                        .then(function () {
                            sliding(function () {
                                relocate(e.node.id, e.parent, e.index);
                            });
                        });
                });
            }, Promise.resolve()).then(function () {
                const want = new Map();
                entries.forEach(function (e) {
                    e.states.forEach(function (on, id) {
                        want.set(id, on);
                    });
                });
                return restoreStates(entries.map(function (e) {
                    return e.node;
                }), want);
            }).then(function () {
                const node = order[0].node;
                openTo(node);
                markLanded(node);
                notify(I18N.undone);
                afterFilter(function () {
                    const a = document.activeElement;
                    if (!a || a === document.body || !a.isConnected || a.closest('.osc-toast')) {
                        const h = node.el.querySelector('.cat-handle');
                        if (h) {
                            h.focus();
                        }
                    }
                });
            }).catch(undoFailed);
        }

        // Undo of an enable or disable: the rows go back to the states they had.
        function undoStates(tops, before) {
            return restoreStates(tops, before).then(function () {
                notify(I18N.undone);
            }).catch(undoFailed);
        }

        // After a move under a disabled parent the server switches the category off.
        function followMove(node, parentId, position) {
            relocate(node.id, parentId, position);
            offUnder(node);
            totals();
            openTo(node);
        }

        function toggle(node, opener) {
            const on = !node.enabled;
            const before = snapStates([node]);
            const run = function () {
                post(CFG.enable, new URLSearchParams({id: String(node.id), enabled: on ? '1' : '0'})).then(function (res) {
                    if (!res || res.error || !res.ok) {
                        flash('error', replyError(res));
                        return;
                    }
                    const reached = applyEnabled(node, on, res);
                    totals();
                    refilter();
                    notify(stateSentence(node, on, reached.length), function () {
                        undoStates([node], before);
                    });
                }).catch(function () {
                    flash('error', I18N.ajaxError);
                });
            };
            if (on) {
                run();
            } else {
                confirmDisable([node], false, opener).then(function (yes) {
                    if (yes) {
                        run();
                    }
                });
            }
        }

        // Deleting asks the server what would go first, so the confirm names the real counts.
        // o = {bulk, opener, instead}. Resolves the preview when the person confirms, else null.
        function confirmDelete(nodes, o) {
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
                const cats = pv.categories;
                const subs = cats - nodes.length;
                const lst = pv.listings;
                const picked = pickedCount(nodes, o.bulk);
                const one = nodes.length === 1 && picked === 1;
                const facts = [];
                const inside = overlapNote(nodes, o.bulk);
                if (inside) {
                    facts.push(['', inside]);
                }
                if (subs > 0) {
                    facts.push(['bad', one ? plural('deleteSubs', subs) : plural('withSubs', cats)]);
                }
                facts.push(lst ? ['bad', plural('deleteListings', lst)] : ['', I18N.deleteNoListings]);
                facts.push(['', I18N.deleteForever]);
                if (pv.queued) {
                    facts.push(['', I18N.deleteQueued]);
                }
                const aside = lst > 0 && nodes.some(function (n) {
                    return n.enabled;
                }) ? {text: I18N.deleteAside, label: I18N.disableInstead, run: o.instead} : null;
                return ask({
                    title: one ? oscFill(I18N.deleteConfirm, [nodes[0].name]) : plural('deleteSelTitle', picked),
                    facts: facts,
                    ok: plural('deleteButton', cats),
                    danger: true,
                    aside: aside,
                    opener: o.opener,
                    node: nodes[0]
                }).then(function (yes) {
                    return yes ? pv : null;
                });
            });
        }

        // "Deleted Vehicles, 7 subcategories and 30 listings." / "Deleted 8 categories and 30 listings."
        function deletedSentence(only, cats, lst) {
            const parts = only
                ? [only.name].concat(cats > 1 ? [plural('subcategoriesN', cats - 1)] : [])
                : [plural('categoriesN', cats)];
            if (lst > 0) {
                parts.push(plural('listingsN', lst));
            }
            return oscFill(I18N.deletedDone, [listText(parts)]);
        }

        // A delete leaves no row to focus, so the search box takes it rather than the page top.
        function focusAfterDelete() {
            if (search && (!document.activeElement || document.activeElement === document.body)) {
                search.focus();
            }
        }

        function remove(node, opener) {
            confirmDelete([node], {
                bulk: false,
                opener: opener,
                instead: function () {
                    toggle(node, opener);
                }
            }).then(function (pv) {
                if (!pv) {
                    return null;
                }
                return post(CFG.del, new URLSearchParams({id: String(node.id)})).then(function (res) {
                    if (!res || res.error) {
                        flash('error', replyError(res));
                        return;
                    }
                    const gone = new Set(subtree(node).map(function (n) {
                        return n.id;
                    }));
                    const next = nextShown(node, gone);
                    removeBlock(node.id);
                    notify(res.queued ? I18N.deleteQueued : deletedSentence(node, pv.categories, pv.listings));
                    if (next) {
                        next.nameEl.focus();
                    } else {
                        focusAfterDelete();
                    }
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
        let moveOpener = null;

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

        function openMove(nodes, opener) {
            if (!moveDlg || typeof moveDlg.showModal !== 'function' || !nodes.length) {
                return;
            }
            moving = nodes;
            moveOpener = opener || document.activeElement;
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
            const was = list.map(spot);
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
                const done = list.length === 1 ? [list[0].id] : (res.done || []).map(Number);
                sliding(function () {
                    done.forEach(function (id, i) {
                        const node = byId.get(+id);
                        if (node) {
                            followMove(node, parentId, position + i);
                        }
                    });
                });
                moveDlg.close();
                if (list.length > 1) {
                    clearSelection();
                } else {
                    const opener = moveOpener;
                    afterFilter(function () {
                        backTo(opener, list[0]);
                    });
                }
                const moved = was.filter(function (e) {
                    return done.indexOf(e.node.id) !== -1;
                });
                const bad = failedText(res);
                if (bad) {
                    flash('error', bad);
                }
                if (moved.length) {
                    markLanded(moved[0].node);
                    const sentence = list.length === 1
                        ? moveSentence(list[0], {via: 'drag'}, byId.get(was[0].parent) || null)
                        : (res.message || I18N.moved);
                    notify(sentence, function () {
                        undoMoves(moved);
                    });
                } else {
                    announce(I18N.moved);
                }
            }).catch(function () {
                if (submit) {
                    submit.disabled = false;
                }
                moveHint.textContent = I18N.ajaxError;
            });
        }

        // The live "N listings end today." line under "Give them new end dates". It asks the server
        // 200 ms after the last change and stays hidden unless the answer is a number above zero.
        let endTimer = 0;
        let endSeq = 0;
        let endSeen = null;
        let bx = {tops: [], picked: [], items: 0};

        function endLine(exp) {
            const posted = exp.parentElement ? exp.parentElement.querySelector('.cat-exp-posted') : null;
            if (!posted) {
                return null;
            }
            let line = posted.querySelector('.cat-exp-warn');
            if (!line) {
                line = el('p', {class: 'cat-exp-warn form-text', hidden: ''}, [
                    el('i', {class: 'bi bi-exclamation-triangle', 'aria-hidden': 'true'}),
                    el('span')
                ]);
                const redate = posted.querySelector('input[value="new"]');
                if (posted.id && redate) {
                    line.id = posted.id + '-warn';
                    redate.setAttribute('aria-describedby', line.id);
                }
                posted.append(line);
            }
            return line;
        }

        // Rows chosen one level at a time, so no row in a group is inside another and the server keeps all of them.
        function layersOf(picked) {
            const set = new Set(picked.map(function (n) {
                return n.id;
            }));
            const out = [];
            picked.forEach(function (n) {
                const k = ancestors(n).filter(function (a) {
                    return set.has(a.id);
                }).length;
                (out[k] = out[k] || []).push(n);
            });
            return out.filter(Boolean);
        }

        // What the expiry control in the dialog or the drawer would reach: groups of ids, and whether subcategories ride along.
        function reachFor(exp) {
            if (expiryDlg && expiryDlg.contains(exp)) {
                const sub = document.getElementById('cat-bx-sub').checked;
                return {sub: sub, groups: (sub ? [bx.tops] : layersOf(bx.picked)).map(function (g) {
                    return g.map(function (n) {
                        return n.id;
                    });
                })};
            }
            const box = drawerBody ? drawerBody.querySelector('#apply_changes_to_subcategories') : null;
            return drawerFor && drawerFor.id ? {sub: !!(box && box.checked), groups: [[drawerFor.id]]} : null;
        }

        function refreshEndNow(exp) {
            const line = endLine(exp);
            if (!line) {
                return;
            }
            window.clearTimeout(endTimer);
            const seq = ++endSeq;
            const posted = line.parentElement;
            const pick = posted.querySelector('input[name="expiry_listings"]:checked');
            const days = expiryDays(exp);
            const reach = reachFor(exp);
            line.hidden = true;
            endSeen = null;
            if (posted.hidden || !pick || pick.value !== 'new' || !days || days < 1 || !reach || !CFG.expiryPreview) {
                return;
            }
            endTimer = window.setTimeout(function () {
                Promise.all(reach.groups.map(function (ids) {
                    return request(CFG.expiryPreview + '&days=' + days + ids.map(function (id) {
                        return '&ids[]=' + id;
                    }).join('') + (reach.sub ? '&to_subcategories=1' : ''));
                })).then(function (answers) {
                    if (seq !== endSeq) {
                        return;
                    }
                    let sum = 0;
                    for (const a of answers) {
                        if (!a || typeof a.endNow !== 'number') {
                            return;
                        }
                        sum += a.endNow;
                    }
                    endSeen = sum;
                    line.lastChild.textContent = plural('endToday', sum);
                    line.hidden = sum < 1;
                }).catch(function () {
                    // The line is a courtesy; without it the dialog still works.
                });
            }, 200);
        }

        // Rows the dialog will change: every selected row, and everything below the top ones when ticked.
        function expiryTargets() {
            const ids = new Set(bx.picked.map(function (n) {
                return n.id;
            }));
            if (document.getElementById('cat-bx-sub').checked) {
                bx.tops.forEach(function (n) {
                    subtree(n).forEach(function (s) {
                        ids.add(s.id);
                    });
                });
            }
            return rows.filter(function (n) {
                return ids.has(n.id);
            });
        }

        // The parts of the dialog that follow the choices: facts, the button's count, the live line.
        function syncExpiryDialog() {
            const targets = expiryTargets();
            const facts = bx.items > 0 ? [] : [['', targets.length === 1 ? I18N.expiryNoListOne : I18N.expiryNoListMany]];
            const list = document.getElementById('catExpiryFacts');
            list.replaceChildren(...factNodes(facts));
            list.hidden = !facts.length;
            document.getElementById('catExpiryOk').textContent = oscFill(I18N.expirySet, [plural('categoriesN', targets.length)]);
            const exp = expiryDlg.querySelector('[data-cat-exp]');
            const days = exp ? expiryDays(exp) : null;
            const subs = bx.tops.reduce(function (out, n) {
                return out.concat(subtree(n).slice(1));
            }, []);
            const differ = days === null ? null : subs.filter(function (n) {
                return n.expiry !== days;
            }).length;
            document.getElementById('cat-bx-sub-note').textContent = !document.getElementById('cat-bx-sub').checked
                ? I18N.applyOff
                : (differ === null ? I18N.applyOn : (differ ? plural('applyChange', differ) : I18N.applyNoChange));
            if (exp) {
                refreshEndNow(exp);
            }
        }

        // "Now: after 60 days" / "Now: Mixed (45 days, 60 days)" for the chosen rows.
        function expiryNowLine(picked) {
            const vals = Array.from(new Set(picked.map(function (n) {
                return n.expiry;
            }))).sort(function (a, b) {
                return (a || 1e9) - (b || 1e9);
            });
            const word = function (v) {
                return v ? plural('expiryDays', v) : I18N.never;
            };
            const value = vals.length === 1
                ? (vals[0] ? plural('expiryNowAfter', vals[0]) : I18N.expiryNowNever)
                : oscFill(I18N.expiryMixed, [vals.slice(0, 3).map(word).join(', ') + (vals.length > 3 ? ', …' : '')]);
            const parts = I18N.expiryNow.split('%s');
            document.getElementById('catExpiryNow').replaceChildren(parts[0], el('strong', {text: value}), parts.slice(1).join(''));
        }

        // Set expiry… for the selection: the drawer's expiry control, with the posted-listings
        // choice shown only when the selection holds listings.
        function openExpiry(nodes) {
            if (!expiryDlg || typeof expiryDlg.showModal !== 'function' || !nodes.length) {
                return;
            }
            const chosen = rows.filter(function (n) {
                return selected.has(n.id);
            });
            const picked = chosen.length ? chosen : nodes;
            bx = {tops: nodes, picked: picked, items: listingsIn(nodes)};
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
            const below = nodes.reduce(function (sum, n) {
                return sum + n.below;
            }, 0);
            if (exp) {
                exp.dataset.saved = '-1';
                exp.dataset.posted = bx.items > 0 ? 'on' : 'off';
                syncPosted(exp, bx.items > 0);
            }
            const sub = document.getElementById('cat-bx-sub');
            sub.checked = false;
            sub.closest('.form-check').hidden = below === 0;
            document.getElementById('cat-bx-sub-label').textContent = plural('applyLabel', below);
            document.getElementById('catExpiryTitle').textContent = picked.length === 1 ? oscFill(I18N.expiryFor, [picked[0].name]) : plural('expiryForMany', picked.length);
            expiryNowLine(picked);
            syncExpiryDialog();
            expiryDlg.showModal();
        }

        function submitExpiry(form) {
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
            const how = keep || !pick ? 'keep' : pick.value;
            const toSub = form.querySelector('#cat-bx-sub').checked;
            const targets = expiryTargets();
            const reach = targets.map(function (n) {
                return {node: n, days: n.expiry};
            });
            const ended = how === 'new' ? endSeen : null;
            const tops = bx.tops;
            const items = bx.items;
            const submit = document.getElementById('catExpiryOk');
            const groups = toSub ? [tops] : layersOf(bx.picked);
            const reached = [];
            submit.disabled = true;
            groups.reduce(function (chain, group) {
                return chain.then(function () {
                    const body = new URLSearchParams();
                    body.set('do', 'expiry');
                    group.forEach(function (n) {
                        body.append('ids[]', String(n.id));
                    });
                    body.set('days', String(days));
                    body.set('expiry_listings', how);
                    if (toSub) {
                        body.set('to_subcategories', '1');
                    }
                    return must(CFG.bulk, body).then(function (res) {
                        reached.push(...(res.done || []), ...(res.queued || []));
                    });
                });
            }, Promise.resolve()).then(function () {
                reached.forEach(function (id) {
                    const node = byId.get(+id);
                    if (node) {
                        (toSub ? subtree(node) : [node]).forEach(function (n) {
                            n.expiry = days;
                            setExpiryCell(n);
                        });
                    }
                });
                expiryDlg.close();
                if (bulkSelect) {
                    bulkSelect.focus();
                }
                clearSelection();
                const who = targets.length === 1 ? targets[0].name : plural('categoriesN', targets.length);
                let msg = days > 0 ? oscFill(I18N.expiryDoneAfter, [who, plural('expiryDays', days)]) : oscFill(I18N.expiryDoneNever, [who]);
                if (items > 0 || how === 'new') {
                    if (how !== 'new') {
                        msg += ' ' + I18N.postedKeep;
                    } else if (days === 0) {
                        msg += ' ' + I18N.postedNone;
                    } else {
                        msg += ' ' + (ended ? plural('postedNewEnded', ended) : I18N.postedNew);
                    }
                }
                notify(msg, how === 'keep' ? function () {
                    undoExpiry(reach);
                } : null);
            }).catch(function (e) {
                flash('error', e && e.reply ? e.reply : I18N.ajaxError);
            }).then(function () {
                submit.disabled = false;
            });
        }

        // Undo of Set expiry (end dates kept): one request per old value and level, so the server keeps every row.
        function undoExpiry(reach) {
            const byDays = new Map();
            reach.forEach(function (r) {
                if (!byDays.has(r.days)) {
                    byDays.set(r.days, []);
                }
                byDays.get(r.days).push(r.node);
            });
            const groups = [];
            byDays.forEach(function (nodes, days) {
                layersOf(nodes).forEach(function (layer) {
                    groups.push([days, layer]);
                });
            });
            return groups.reduce(function (chain, g) {
                return chain.then(function () {
                    const body = new URLSearchParams();
                    body.set('do', 'expiry');
                    g[1].forEach(function (n) {
                        body.append('ids[]', String(n.id));
                    });
                    body.set('days', String(g[0]));
                    body.set('expiry_listings', 'keep');
                    return must(CFG.bulk, body);
                });
            }, Promise.resolve()).then(function () {
                reach.forEach(function (r) {
                    r.node.expiry = r.days;
                    setExpiryCell(r.node);
                });
                notify(I18N.undone);
            }).catch(undoFailed);
        }

        // The bulk bar: only the top-most selected rows go to the server, the rest ride along.
        function runBulk(what) {
            const ids = topSelected();
            let nodes = ids.map(function (id) {
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
            if (what === 'disable') {
                nodes = nodes.filter(function (n) {
                    return subtree(n).some(function (s) {
                        return s.enabled;
                    });
                });
                if (!nodes.length) {
                    notify(I18N.alreadyOff);
                    return;
                }
            }
            const before = snapStates(nodes);
            const finish = function (res, pv) {
                if (!res || res.error || !res.ok) {
                    flash('error', replyError(res));
                    return;
                }
                const bad = failedText(res);
                const reached = (res.done || []).concat(res.queued || []);
                let cats = 0;
                let lst = 0;
                const first = reached.length ? byId.get(+reached[0]) : null;
                reached.forEach(function (id) {
                    const node = byId.get(+id);
                    if (!node) {
                        return;
                    }
                    cats += 1 + node.below;
                    lst += node.listings;
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
                if (bad) {
                    flash('error', bad);
                } else if (what === 'delete') {
                    const all = pv && reached.length === nodes.length;
                    notify(res.queued && res.queued.length ? I18N.deleteQueued : deletedSentence(reached.length === 1 && first ? first : null, all ? pv.categories : cats, all ? pv.listings : lst));
                    focusAfterDelete();
                } else if (first) {
                    const on = what === 'enable';
                    notify(reached.length === 1 ? stateSentence(first, on, cats) : oscFill(on ? I18N.enabledOne : I18N.disabledOne, [plural('categoriesN', cats)]), function () {
                        undoStates(nodes, before);
                    });
                }
            };
            const run = function (pv) {
                const body = new URLSearchParams();
                body.set('do', what);
                nodes.forEach(function (n) {
                    body.append('ids[]', String(n.id));
                });
                return post(CFG.bulk, body).then(function (res) {
                    finish(res, pv);
                }).catch(function () {
                    flash('error', I18N.ajaxError);
                });
            };
            if (what === 'delete') {
                confirmDelete(nodes, {
                    bulk: true,
                    opener: bulkSelect,
                    instead: function () {
                        runBulk('disable');
                    }
                }).then(function (pv) {
                    return pv ? run(pv) : null;
                });
            } else if (what === 'disable') {
                confirmDisable(nodes, true, bulkSelect).then(function (yes) {
                    return yes ? run(null) : null;
                });
            } else {
                run(null);
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
            const onDialogInput = function (e) {
                onExpiryInput(e);
                syncExpiryDialog();
            };
            expiryForm.addEventListener('input', onDialogInput);
            expiryForm.addEventListener('change', onDialogInput);
            expiryDlg.addEventListener('close', function () {
                if (bulkSelect) {
                    bulkSelect.focus();
                }
            });
        }

        if (window.osc && typeof window.osc.toast === 'function' && window.osc.toast.labels) {
            window.osc.toast.labels({undo: I18N.undo, close: I18N.close});
        }

        // --- Drag and arrow keys: the shared tree layer in ui-osc.js -------------------------
        const place = function (parent) {
            return parent ? parent.name : I18N.topLevel;
        };

        // Shows a group's rows at once, without the filter pass, which would unhide a row in flight.
        function openGroup(node) {
            node.open = true;
            setExpanded(node, true);
            subtree(node).slice(1).forEach(function (n) {
                const p = byId.get(n.parent);
                n.shown = !!p && p.open && p.shown;
                n.el.hidden = !n.shown;
            });
        }

        // The slot's box starts where the name of a row at its depth starts. The offset is that
        // for a top-level row, less the slot's own side padding; each level adds one indent step.
        const slotOffset = function () {
            const first = table.querySelector('tr.cat-row[data-depth="1"]:not([hidden])') || table.querySelector('tr.cat-row:not([hidden])');
            const name = first && first.querySelector('.cat-name');
            if (!name) {
                return 0;
            }
            const range = document.createRange();
            range.selectNodeContents(name);
            const text = range.getClientRects()[0] || name.getBoundingClientRect();
            const row = first.getBoundingClientRect();
            const rtl = getComputedStyle(first).direction === 'rtl';
            const pad = parseFloat(getComputedStyle(app).getPropertyValue('--cat-pad-x')) || 0;
            const padPx = /rem$/.test(getComputedStyle(app).getPropertyValue('--cat-pad-x').trim())
                ? pad * parseFloat(getComputedStyle(document.documentElement).fontSize) : pad;
            return (rtl ? row.right - text.right : text.left - row.left) - padPx;
        };

        function moveSentence(node, m, oldParent) {
            const list = node.parent ? byId.get(node.parent).kids : roots;
            const where = place(byId.get(node.parent));
            const at = list.indexOf(node) + 1;
            if (m.via === 'key') {
                if (m.key === 'up' || m.key === 'down') {
                    return oscFill(m.key === 'up' ? I18N.movedUp : I18N.movedDown, [node.name, at, list.length, where]);
                }
                if (m.key === 'in') {
                    return oscFill(I18N.movedUnder, [node.name, where, list.length]);
                }
                return oscFill(I18N.movedOut, [node.name, place(oldParent), where]);
            }
            return oscFill(I18N.movedTo, [node.name, where, at, list.length]);
        }

        function commitMove(m) {
            const node = byId.get(+m.el.dataset.id);
            if (!node) {
                return;
            }
            const target = m.parentEl ? byId.get(+m.parentEl.dataset.id) : null;
            const oldParent = byId.get(node.parent) || null;
            const oldList = oldParent ? oldParent.kids : roots;
            const oldIndex = oldList.indexOf(node);
            const was = {node: node, parent: oldParent ? oldParent.id : 0, index: oldIndex, states: snapStates([node])};
            const newParent = target ? target.id : 0;
            relocate(node.id, newParent, m.index);
            if (target) {
                openGroup(target);
            }
            const sentence = moveSentence(node, m, oldParent);
            const back = function (text) {
                if (sorter) {
                    sorter.flip(function () {
                        relocate(node.id, oldParent ? oldParent.id : 0, oldIndex);
                    });
                } else {
                    relocate(node.id, oldParent ? oldParent.id : 0, oldIndex);
                }
                // The flash bar is a live region of its own, so the error is not announced twice.
                flash('error', text);
            };
            return post(CFG.move, new URLSearchParams({id: String(node.id), parent_id: String(newParent), position: String(m.index)}))
                .then(function (res) {
                    if (!res || res.error || !res.ok) {
                        back(replyError(res));
                        return;
                    }
                    offUnder(node);
                    totals();
                    notify(sentence, function () {
                        undoMoves([was]);
                    });
                })
                .catch(function () {
                    back(I18N.ajaxError);
                });
        }

        function moveRefused(reason, info) {
            const node = info && info.el ? byId.get(+info.el.dataset.id) : null;
            const deep = [I18N.notMoved, I18N.tooDeepDrag].filter(Boolean).join(' ');
            let text = '';
            if (reason === 'escape') {
                text = I18N.moveCancelled;
            } else if (reason === 'deep') {
                text = deep;
            } else if (reason === 'filtered') {
                notify(I18N.filterReorder);
                return;
            } else if (reason === 'refused-key' && node) {
                const where = place(byId.get(node.parent));
                text = {
                    first: oscFill(I18N.alreadyFirst, [node.name, where]),
                    last: oscFill(I18N.alreadyLast, [node.name, where]),
                    top: oscFill(I18N.alreadyTop, [node.name]),
                    'nothing-above': oscFill(I18N.nothingAbove, [node.name]),
                    deep: deep
                }[info.refused] || '';
            }
            if (text) {
                announce(text);
            }
        }

        const sorter = tbody && typeof window.osc === 'object' && typeof window.osc.sortable === 'function'
            ? window.osc.sortable(table, {
                handle: '.cat-handle',
                tree: {
                    rows: 'tr.cat-row',
                    depth: function (row) {
                        return (+row.dataset.depth || 1) - 1;
                    },
                    indent: null,
                    maxLevels: CFG.limit,
                    canMove: function () {
                        return !filtering();
                    },
                    isCollapsed: function (row) {
                        const n = byId.get(+row.dataset.id);
                        return !!n && n.kids.length > 0 && !n.open;
                    },
                    open: function (row) {
                        const n = byId.get(+row.dataset.id);
                        if (n) {
                            openGroup(n);
                        }
                    },
                    label: function (row) {
                        return row ? oscFill(I18N.inside, [byId.get(+row.dataset.id).name]) : I18N.topLevel;
                    },
                    tooDeep: I18N.tooDeepDrag || '',
                    ghost: function (row, below) {
                        const n = byId.get(+row.dataset.id);
                        return {name: n ? n.name : '', more: below > 0 ? plural('moreSubs', below) : ''};
                    },
                    slotOffset: slotOffset,
                    keys: true,
                    hint: [I18N.hintMove, I18N.hintIn, I18N.hintOut],
                    commit: commitMove,
                    cancelled: moveRefused
                }
            })
            : null;

        // --- Phone sheet --------------------------------------------------------------------
        const sheet = document.getElementById('catSheet');
        let sheetNode = null;
        let sheetOpener = null;
        let sheetRan = false;

        function openSheet(node, opener) {
            if (!sheet || typeof sheet.showModal !== 'function') {
                return;
            }
            sheetNode = node;
            sheetOpener = opener;
            sheetRan = false;
            document.getElementById('catSheetTitle').textContent = node.name;
            sheet.querySelector('[data-cat-act="toggle"]').textContent = node.enabled ? I18N.disable : I18N.enable;
            const add = sheet.querySelector('[data-cat-act="addsub"]');
            const noChild = node.el.hasAttribute('data-no-child');
            add.disabled = noChild;
            add.title = noChild ? I18N.depthNote : '';
            sheet.showModal();
            sheet.querySelector('button:not(:disabled)').focus();
        }

        if (sheet) {
            // Focus goes back to the ⋯ unless the chosen action has opened something of its own.
            sheet.addEventListener('close', function () {
                const opener = sheetOpener;
                if (!sheetRan && opener && opener.isConnected) {
                    opener.focus();
                }
                sheetNode = null;
            });
        }

        // --- Events -------------------------------------------------------------------------
        // Row actions by data-cat-act.
        const actions = {
            more: openSheet,
            edit: function (node, opener) {
                openDrawer({id: node.id, opener: opener});
            },
            addsub: function (node, opener) {
                openDrawer({parent: node.id, opener: opener});
            },
            move: function (node, opener) {
                openMove([node], opener);
            },
            toggle: toggle,
            delete: remove
        };

        app.addEventListener('click', function (e) {
            const disc = e.target.closest('.cat-disclosure');
            if (disc && table && table.contains(disc)) {
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
            const grip = e.target.closest('.cat-handle');
            if (grip && table && table.contains(grip)) {
                if (grip.getAttribute('aria-disabled') === 'true') {
                    notify(I18N.filterReorder);
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
            const inSheet = !!sheet && sheet.contains(btn);
            const tr = inSheet ? (sheetNode && sheetNode.el) : btn.closest('tr.cat-row');
            const node = tr ? byId.get(+tr.dataset.id) : null;
            if (node && actions[act]) {
                e.preventDefault();
                let opener = btn;
                if (inSheet) {
                    sheetRan = true;
                    opener = sheetOpener;
                    sheet.close();
                }
                actions[act](node, opener);
            }
        });

        if (table) {
            // Row actions say which row they belong to once focus reaches them; set lazily, as a
            // large tree would otherwise carry the same attributes thousands of times.
            table.addEventListener('focusin', function (e) {
                const acts = e.target.closest('.cat-actions');
                const tr = acts ? acts.closest('tr.cat-row') : null;
                if (tr && !acts.hasAttribute('role')) {
                    acts.setAttribute('role', 'group');
                    acts.setAttribute('aria-labelledby', 'cat-n-' + tr.dataset.id);
                }
            });
            table.addEventListener('change', function (e) {
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
            drawerBody.addEventListener('input', function (e) {
                onExpiryInput(e);
                syncApplyNote();
            });
            drawerBody.addEventListener('change', function (e) {
                const handled = onExpiryInput(e);
                syncApplyNote();
                if (!handled && e.target.id === 'apply_changes_to_subcategories') {
                    const exp = drawerBody.querySelector('[data-cat-exp]');
                    if (exp) {
                        refreshEndNow(exp);
                    }
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
