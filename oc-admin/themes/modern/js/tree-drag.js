/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * osc.treeDrag(container, options, forget): pointer and keyboard moves on a flat list of rows with
 * a depth each (Categories, Menus). The pure list rules are osc.flatTree in ui-osc.js; screens
 * reach this through osc.sortable(el, {tree: {...}}).
 */
/* global osc */
osc.treeDrag = (function () {
    function reduced() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function make(tag, cls, parent) {
        var el = document.createElement(tag);
        if (cls) {
            el.className = cls;
        }
        if (parent) {
            parent.appendChild(el);
        }
        return el;
    }

    // The slot, ghost and shield live in one wrapper: adding or removing a child of <body>
    // restyles the whole page.
    var layerEl = null;

    function layer() {
        if (!layerEl || !layerEl.isConnected) {
            layerEl = make('div', 'tree-layer', document.body);
        }
        return layerEl;
    }

    // Slides started here, one per element, so a new slide can stop the last one without
    // getAnimations(), which recalculates style for the whole list.
    var slides = new WeakMap();

    function stopSlides(el) {
        var a = slides.get(el);
        if (a) {
            a.cancel();
            slides.delete(el);
        }
    }

    function slide(el, frames, opts) {
        stopSlides(el);
        var a = el.animate(frames, opts);
        slides.set(el, a);
        a.onfinish = function () {
            if (slides.get(el) === a) {
                slides.delete(el);
            }
        };
        return a;
    }

    // Shown rows from `list` (in document order) within 100px of the viewport, with their tops.
    // Finds the first one by bisection, so a long list costs a few reads, not one per row.
    function inView(list) {
        var vh = window.innerHeight;
        var shown = list.filter(function (el) { return !el.hidden && el.isConnected; });
        var lo = 0;
        var hi = shown.length;
        while (lo < hi) {
            var mid = (lo + hi) >> 1;
            if (shown[mid].getBoundingClientRect().bottom > -100) {
                hi = mid;
            } else {
                lo = mid + 1;
            }
        }
        var out = new Map();
        for (var k = lo; k < shown.length; k++) {
            var r = shown[k].getBoundingClientRect();
            if (r.top > vh + 100) {
                break;
            }
            out.set(shown[k], r.top);
        }
        return out;
    }

    // FLIP: rows in view slide from where they were to where they are after mutate(). `list`
    // returns the rows to watch; `first` may give a starting top (a dropped row starts at the ghost).
    function flipRows(list, mutate, first, ease) {
        if (reduced()) {
            mutate();
            return;
        }
        var seen = inView(list());
        if (first) {
            first.forEach(function (top, el) { seen.set(el, top); });
        }
        var had = seen.size;
        mutate();
        var now = inView(list());
        now.forEach(function (top, el) {
            if (!seen.has(el)) {
                if (had) {
                    slide(el, [{opacity: 0}, {opacity: 1}], {duration: 160, easing: ease});
                }
                return;
            }
            var dy = seen.get(el) - top;
            if (Math.abs(dy) >= 1) {
                slide(el, [{transform: 'translateY(' + dy + 'px)'}, {transform: 'none'}], {duration: 200, easing: ease});
            } else {
                stopSlides(el);
            }
        });
    }

    // The arrow-key help shown under a focused handle; texts are [move, in, out].
    function keyHint(texts) {
        var box = null;
        return {
            show: function (h, back) {
                if (!texts) {
                    return;
                }
                if (!box) {
                    box = make('div', 'tree-key-hint', layer());
                    box.setAttribute('aria-hidden', 'true');
                }
                if (box.dataset.back !== String(back)) {
                    var lines = [[['↑', '↓'], texts[0]], [[back ? '←' : '→'], texts[1]], [[back ? '→' : '←'], texts[2]]];
                    box.textContent = '';
                    lines.forEach(function (line) {
                        var div = make('div', '', box);
                        line[0].forEach(function (k) {
                            make('kbd', '', div).textContent = k;
                            div.appendChild(document.createTextNode(' '));
                        });
                        div.appendChild(document.createTextNode(line[1]));
                    });
                    box.dataset.back = String(back);
                }
                box.hidden = false;
                var r = h.getBoundingClientRect();
                var w = box.offsetWidth;
                var bh = box.offsetHeight;
                box.style.top = Math.max(8, Math.min(r.bottom + 6, window.innerHeight - bh - 8)) + 'px';
                box.style.left = (back ? Math.max(8, r.right - w) : Math.max(8, Math.min(r.left, window.innerWidth - w - 8))) + 'px';
            },
            hide: function () {
                if (box) {
                    box.hidden = true;
                }
            },
            remove: function () {
                if (box) {
                    box.remove();
                    box = null;
                }
            }
        };
    }

    function tree(container, options, forget) {
        var cfg = options.tree;
        var handleSel = options.handle || '[data-tree-handle]';
        var FT = osc.flatTree;
        var maxLevels = cfg.maxLevels || 0;
        var press = null;
        var drag = null;
        var placed = null;
        var hint = keyHint(cfg.keys && Array.isArray(cfg.hint) && cfg.hint.length >= 3 ? cfg.hint : null);
        var swallow = false;
        var ease = getComputedStyle(document.documentElement).getPropertyValue('--osc-ease').trim() || 'cubic-bezier(.2, 0, 0, 1)';
        layer();

        function rows() {
            return Array.prototype.slice.call(container.querySelectorAll(cfg.rows));
        }

        function model(all) {
            return all.map(function (el) { return {el: el, depth: cfg.depth(el)}; });
        }

        function rowOf(el) {
            var h = el && el.closest ? el.closest(handleSel) : null;
            var row = h ? h.closest(cfg.rows) : null;
            return row && container.contains(row) ? {h: h, row: row} : null;
        }

        // :dir() reads the document's direction without a style recalculation.
        function rtl() {
            try {
                return container.matches(':dir(rtl)');
            } catch {
                return getComputedStyle(container).direction === 'rtl';
            }
        }

        // The indent step in px from --tree-indent (px, rem or em), without adding a probe to the list.
        function unit() {
            if (typeof cfg.indent === 'number' && cfg.indent > 0) {
                return cfg.indent;
            }
            var v = getComputedStyle(container).getPropertyValue('--tree-indent').trim();
            var n = parseFloat(v);
            if (!(n > 0)) {
                return 24;
            }
            if (/rem$/.test(v)) {
                return n * parseFloat(getComputedStyle(document.documentElement).fontSize);
            }
            if (/em$/.test(v)) {
                return n * parseFloat(getComputedStyle(container).fontSize);
            }
            return n;
        }

        function cancelled(reason, info) {
            if (cfg.cancelled) {
                cfg.cancelled(reason, info);
            }
        }

        // A move whose save is still on its way: keys and new drags wait for it, so moves reach the
        // server one at a time and a failed one rolls back only itself.
        var pending = null;

        function settle(result) {
            if (result && typeof result.then === 'function') {
                var p = Promise.resolve(result).catch(function () {}).then(function () {
                    if (pending === p) {
                        pending = null;
                    }
                });
                pending = p;
            }
        }

        function flip(mutate, first) {
            flipRows(rows, mutate, first, ease);
        }

        function flash(row) {
            row.classList.remove('is-tree-flash');
            void row.offsetWidth;
            row.classList.add('is-tree-flash');
            setTimeout(function () { row.classList.remove('is-tree-flash'); }, 1700);
        }

        function tagText(tag, text, refused) {
            tag.textContent = '';
            if (refused) {
                make('i', 'bi bi-exclamation-triangle', tag).setAttribute('aria-hidden', 'true');
            }
            tag.appendChild(document.createTextNode(text));
        }

        function clearPlaced() {
            if (!placed) {
                return;
            }
            clearTimeout(placed.timer);
            placed.tag.remove();
            placed.row.classList.remove('is-tree-placed', 'is-refused');
            if (placed.parent) {
                placed.parent.classList.remove('is-drop-parent');
            }
            placed = null;
        }

        // The "Inside Cars" tag and parent tint a keyboard move leaves for a moment.
        function markPlaced(row, refused) {
            clearPlaced();
            var all = rows();
            var items = model(all);
            var i = all.indexOf(row);
            var p = i < 0 ? -1 : FT.parentAt(items, i, items[i].depth);
            var parent = refused || p < 0 ? null : all[p];
            var tag = make('span', 'tree-place-tag' + (refused ? ' is-refused' : ''));
            tag.setAttribute('aria-hidden', 'true');
            tagText(tag, refused ? cfg.tooDeep || '' : cfg.label(parent), refused);
            var h = row.querySelector(handleSel);
            (h ? h.parentNode : row).appendChild(tag);
            row.classList.add('is-tree-placed');
            row.classList.toggle('is-refused', !!refused);
            if (parent) {
                parent.classList.add('is-drop-parent');
            }
            placed = {row: row, tag: tag, parent: parent, timer: setTimeout(clearPlaced, 1600)};
        }

        function hideHint() {
            hint.hide();
        }

        function showHint(h) {
            hint.show(h, rtl());
        }

        function scroller() {
            for (var el = container.parentElement; el && el !== document.body && el !== document.documentElement; el = el.parentElement) {
                var oy = getComputedStyle(el).overflowY;
                if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight) {
                    return el;
                }
            }
            return null;
        }

        // Layout position from the offset chain, so rows still sliding (transforms) read where they will be.
        function layoutY(el, seen) {
            if (!el) {
                return 0;
            }
            if (!seen.has(el)) {
                seen.set(el, el.offsetTop + layoutY(el.offsetParent, seen));
            }
            return seen.get(el);
        }

        // The slot floats over the list; the rows make room for it through a class on the row it sits
        // before (or after, at the end), so a move restyles one row instead of the whole list.
        function setGap(d, on) {
            var n = d.vis ? d.vis.length : 0;
            if (d.gapEl) {
                d.gapEl.classList.remove('is-tree-gap-before', 'is-tree-gap-after');
                d.gapEl.style.removeProperty('--tree-gap');
                d.gapEl = null;
            }
            if (!on || !n) {
                return;
            }
            var el = d.slotPos < n ? d.vis[d.slotPos] : d.vis[n - 1];
            el.style.setProperty('--tree-gap', d.slotH + 'px');
            el.classList.add(d.slotPos < n ? 'is-tree-gap-before' : 'is-tree-gap-after');
            d.gapEl = el;
        }

        function placeSlot() {
            var d = drag;
            var n = d.vis.length;
            var y = !n ? 0 : (d.slotPos < n ? d.tops[d.slotPos] : d.tops[n - 1] + d.hs[n - 1]);
            d.slot.style.top = (d.top + y) + 'px';
            d.slot.style.left = d.left + 'px';
            d.slot.style.width = d.width + 'px';
        }

        // Row tops relative to the container, without the gap; re-read after an open or a resize
        // only, so a pointer move does no layout work for long lists.
        function measure() {
            var d = drag;
            var seen = new Map();
            var anchor = d.vis ? (d.slotPos < d.vis.length ? d.vis[d.slotPos] : null) : d.anchor;
            setGap(d, false);
            d.vis = rows().filter(function (r) { return !r.hidden; });
            d.items = model(d.vis);
            var box = container.getBoundingClientRect();
            d.top = box.top;
            d.left = box.left;
            d.width = box.width;
            d.slotH = d.slot.offsetHeight;
            var base = layoutY(container, seen);
            var at = anchor ? d.vis.indexOf(anchor) : -1;
            d.slotPos = at < 0 ? d.vis.length : at;
            d.tops = [];
            d.hs = [];
            d.vis.forEach(function (r) {
                d.tops.push(r.offsetTop + layoutY(r.offsetParent, seen) - base);
                d.hs.push(r.offsetHeight);
            });
            d.stale = false;
            setGap(d, true);
        }

        function topOf(k) {
            return drag.tops[k] + (k >= drag.slotPos ? drag.slotH : 0);
        }

        // First visible row whose middle (edge = false) or bottom (edge = true) is below py.
        function seek(py, edge) {
            var lo = 0;
            var hi = drag.vis.length;
            while (lo < hi) {
                var mid = (lo + hi) >> 1;
                if (topOf(mid) + (edge ? drag.hs[mid] : drag.hs[mid] / 2) > py) {
                    hi = mid;
                } else {
                    lo = mid + 1;
                }
            }
            return lo;
        }

        function moveSlot(to) {
            var d = drag;
            var old = d.slotPos;
            d.slotPos = to;
            setGap(d, true);
            if (reduced()) {
                return;
            }
            var lo = Math.min(old, to);
            var hi = Math.max(old, to);
            var dy = to > old ? d.slotH : -d.slotH;
            var span = hi > lo ? d.tops[hi - 1] + d.hs[hi - 1] - d.tops[lo] : 0;
            var vh = window.innerHeight;
            for (var k = lo; k < hi; k++) {
                var y = d.top + topOf(k);
                if (y + d.hs[k] > -100 && y < vh + 100) {
                    slide(d.vis[k], [{transform: 'translateY(' + dy + 'px)'}, {transform: 'none'}], {duration: 200, easing: ease});
                }
            }
            slide(d.slot, [{translate: '0 ' + (to > old ? -span : span) + 'px'}, {translate: '0 0'}], {duration: 200, easing: ease});
        }

        function setHost(host) {
            var d = drag;
            if (host === d.host) {
                return;
            }
            clearTimeout(d.hostTimer);
            if (d.host) {
                d.host.classList.remove('is-will-open');
            }
            d.host = host;
            if (host) {
                host.classList.add('is-will-open');
                d.hostTimer = setTimeout(function () { openHost(host); }, 600);
            }
        }

        function openHost(host) {
            if (!drag || drag.host !== host) {
                return;
            }
            host.classList.remove('is-will-open');
            drag.host = null;
            flip(function () { cfg.open(host); });
            measure();
            update();
        }

        function update() {
            var d = drag;
            if (!d) {
                return;
            }
            if (d.stale) {
                measure();
            }
            var gx = d.rtl ? d.x - d.ghostW - 12 : d.x + 12;
            d.ghost.style.transform = 'translate(' + gx + 'px, ' + (d.y - 18) + 'px)';
            var py = d.y - d.top;
            var at = seek(py, false);
            if (at !== d.slotPos) {
                moveSlot(at);
            }
            placeSlot();
            var r = FT.range(d.items, d.slotPos);
            var dx = (d.x - d.x0) * (d.rtl ? -1 : 1);
            var depth = Math.max(r.min, Math.min(r.max, d.depth0 + Math.round(dx / d.unit)));
            var p = FT.parentAt(d.items, d.slotPos, depth);
            var parent = p >= 0 ? d.vis[p] : null;
            var refused = !FT.fits(d.height, depth, maxLevels);
            d.depth = depth;
            d.refused = refused;
            d.slot.style.setProperty('--tree-d', depth);
            d.slot.classList.toggle('is-refused', refused);
            var text = refused ? cfg.tooDeep || '' : cfg.label(parent);
            if (text !== d.text || refused !== d.wasRefused) {
                tagText(d.tag, text, refused);
                d.text = text;
                d.wasRefused = refused;
            }
            var tint = refused ? null : parent;
            if (d.tint !== tint) {
                if (d.tint) {
                    d.tint.classList.remove('is-drop-parent');
                }
                if (tint) {
                    tint.classList.add('is-drop-parent');
                }
                d.tint = tint;
            }
            d.parent = parent;
            // A collapsed group opens after a short hover on it, or while the slot is its last child.
            var o = seek(py, true);
            var over = o < d.vis.length && topOf(o) <= py ? d.vis[o] : null;
            var shut = function (el) { return !!el && !!cfg.isCollapsed && cfg.isCollapsed(el); };
            var host = shut(over) ? over : (tint && tint === d.vis[d.slotPos - 1] && shut(tint) ? tint : null);
            setHost(cfg.open ? host : null);
        }

        // Edge auto-scroll, faster the closer the pointer is to the edge.
        function tick() {
            var d = drag;
            if (!d) {
                return;
            }
            var box = d.scroller ? d.scroller.getBoundingClientRect() : {top: 0, bottom: window.innerHeight};
            var zone = 72;
            var v = 0;
            if (d.y < box.top + zone) {
                v = -18 * Math.pow(Math.min(1, (box.top + zone - d.y) / zone), 2);
            } else if (d.y > box.bottom - zone) {
                v = 18 * Math.pow(Math.min(1, (d.y - box.bottom + zone) / zone), 2);
            }
            // Instant, or a smooth scroll-behavior restarts every frame and the page barely moves.
            if (v) {
                (d.scroller || window).scrollBy({top: v, behavior: 'instant'});
            }
            d.raf = requestAnimationFrame(tick);
        }

        function onScroll() {
            if (drag) {
                drag.top = container.getBoundingClientRect().top;
                update();
            }
        }

        function onResize() {
            if (drag) {
                drag.stale = true;
                drag.unit = unit();
                update();
            }
        }

        function start(x, y) {
            var p = press;
            press = null;
            clearTimeout(p.timer);
            hideHint();
            clearPlaced();
            var all = rows();
            var items = model(all);
            var from = all.indexOf(p.row);
            var block = all.slice(from, FT.blockEnd(items, from));
            var h = p.row.getBoundingClientRect().height;
            var slot = make('div', 'tree-slot');
            slot.setAttribute('aria-hidden', 'true');
            var tag = make('span', 'tree-slot-tag', make('div', 'tree-slot-box', slot));
            var vars = getComputedStyle(container);
            if (cfg.slotOffset) {
                slot.style.setProperty('--tree-slot-offset', cfg.slotOffset() + 'px');
            }
            ['--tree-indent', '--tree-slot-pad', '--tree-slot-pad-x', '--tree-slot-pad-start'].forEach(function (v) {
                var val = vars.getPropertyValue(v).trim();
                if (val) {
                    slot.style.setProperty(v, val);
                }
            });
            slot.style.setProperty('--tree-slot-h', Math.max(28, Math.min(h - 12, 64)) + 'px');
            var shown = cfg.ghost ? cfg.ghost(p.row, block.length - 1) : {name: '', more: ''};
            var ghost = make('div', 'tree-ghost');
            ghost.setAttribute('aria-hidden', 'true');
            make('i', 'bi bi-grip-vertical', ghost);
            make('span', 'tree-ghost-name', ghost).textContent = shown.name || '';
            if (shown.more) {
                make('small', 'tree-ghost-more', ghost).textContent = shown.more;
            }
            drag = {
                row: p.row, handle: p.h, block: block, was: block.map(function (r) { return r.hidden; }),
                depth0: items[from].depth, height: FT.height(items, from), slot: slot, tag: tag, ghost: ghost,
                anchor: null, gapEl: null, x0: x, y0: y, x: x, y: y, unit: unit(), rtl: rtl(), host: null, hostTimer: 0,
                tint: null, parent: null, depth: items[from].depth, refused: false, text: null, raf: 0, scroller: scroller()
            };
            document.documentElement.classList.add('is-tree-dragging');
            drag.shield = make('div', 'tree-shield', layer());
            var end = from + block.length;
            flip(function () {
                block.forEach(function (r) { r.hidden = true; });
                drag.anchor = all.slice(end).find(function (r) { return !r.hidden; }) || null;
                layer().appendChild(slot);
                measure();
                placeSlot();
            });
            layer().appendChild(ghost);
            drag.ghostW = ghost.offsetWidth;
            if (!reduced()) {
                ghost.animate([{scale: 0.96, opacity: 0.6}, {scale: 1, opacity: 1}], {duration: 140, easing: ease});
            }
            window.addEventListener('scroll', onScroll, true);
            window.addEventListener('resize', onResize);
            update();
            drag.raf = requestAnimationFrame(tick);
        }

        function restore(d) {
            d.block.forEach(function (r, k) { r.hidden = d.was[k]; });
        }

        function finish(d) {
            drag = null;
            cancelAnimationFrame(d.raf);
            clearTimeout(d.hostTimer);
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onResize);
            document.documentElement.classList.remove('is-tree-dragging');
            d.shield.remove();
            [d.host, d.tint].forEach(function (el) {
                if (el) {
                    el.classList.remove('is-will-open', 'is-drop-parent');
                }
            });
        }

        function flyHome(ghost, to) {
            if (reduced() || !to || !ghost.animate) {
                ghost.remove();
                return;
            }
            var a = ghost.getBoundingClientRect();
            var b = to.getBoundingClientRect();
            var anim = ghost.animate([
                {translate: '0 0', opacity: 1},
                {translate: (b.left - a.left) + 'px ' + (b.top - a.top) + 'px', opacity: 0}
            ], {duration: 200, easing: ease, fill: 'forwards'});
            anim.onfinish = function () { ghost.remove(); };
            setTimeout(function () { ghost.remove(); }, 400);
        }

        function cancel(reason) {
            var d = drag;
            if (!d) {
                return;
            }
            finish(d);
            flip(function () {
                setGap(d, false);
                d.slot.remove();
                restore(d);
            });
            flyHome(d.ghost, d.handle);
            cancelled(reason || 'escape');
        }

        function drop() {
            var d = drag;
            if (!d) {
                return;
            }
            if (d.refused) {
                cancel('deep');
                return;
            }
            var all = rows();
            var items = model(all);
            var from = all.indexOf(d.row);
            var end = from + d.block.length;
            var at = d.slotPos < d.vis.length ? all.indexOf(d.vis[d.slotPos]) : all.length;
            if (at >= from && at <= end && d.depth === d.depth0) {
                cancel('same');
                return;
            }
            var move = {
                el: d.row, from: from, at: at, depth: d.depth, parentEl: d.parent,
                index: FT.position(items, at, d.depth, from, end), via: 'drag'
            };
            var start = new Map([[d.row, d.ghost.getBoundingClientRect().top - 6]]);
            var result;
            finish(d);
            flip(function () {
                setGap(d, false);
                d.slot.remove();
                restore(d);
                result = cfg.commit(move);
            }, start);
            d.ghost.remove();
            if (d.row.isConnected) {
                flash(d.row);
            }
            settle(result);
        }

        function dropPress() {
            if (press) {
                clearTimeout(press.timer);
            }
            press = null;
        }

        function onDown(e) {
            if (drag || press || pending || (e.pointerType === 'mouse' && e.button !== 0)) {
                return;
            }
            var hit = rowOf(e.target);
            if (!hit || (cfg.canMove && !cfg.canMove())) {
                return;
            }
            hideHint();
            press = {row: hit.row, h: hit.h, x0: e.clientX, y0: e.clientY, touch: e.pointerType === 'touch', timer: 0};
            try {
                hit.h.setPointerCapture(e.pointerId);
            } catch {
                // A pointer the browser no longer tracks; document listeners still see it.
            }
            if (press.touch) {
                press.timer = setTimeout(function () {
                    if (press) {
                        if (navigator.vibrate) {
                            navigator.vibrate(8);
                        }
                        start(press.x0, press.y0);
                    }
                }, 250);
            }
        }

        function onMove(e) {
            if (press && !drag) {
                var dist = Math.hypot(e.clientX - press.x0, e.clientY - press.y0);
                if (press.touch) {
                    if (dist > 8) {
                        dropPress();
                    }
                    return;
                }
                if (dist < 5) {
                    return;
                }
                start(press.x0, press.y0);
            }
            if (!drag) {
                return;
            }
            drag.x = e.clientX;
            drag.y = e.clientY;
            update();
        }

        function onUp() {
            if (drag) {
                swallow = true;
                drop();
            }
            dropPress();
            setTimeout(function () { swallow = false; }, 0);
        }

        function onCancel() {
            dropPress();
            cancel('escape');
        }

        function onKeyDoc(e) {
            if (drag && e.key === 'Escape') {
                e.preventDefault();
                swallow = true;
                cancel('escape');
            }
        }

        function onTouchMove(e) {
            if (drag) {
                e.preventDefault();
            }
        }

        function onContext(e) {
            if (press || drag) {
                e.preventDefault();
            }
        }

        function onClick(e) {
            if (swallow && rowOf(e.target)) {
                e.preventDefault();
                e.stopPropagation();
                swallow = false;
            }
        }

        function onKey(e) {
            var hit = rowOf(e.target);
            if (!cfg.keys || drag || !hit || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            var back = rtl();
            var key = {ArrowUp: 'up', ArrowDown: 'down', ArrowRight: back ? 'out' : 'in', ArrowLeft: back ? 'in' : 'out'}[e.key];
            if (!key) {
                return;
            }
            e.preventDefault();
            if (pending) {
                return;
            }
            if (cfg.canMove && !cfg.canMove()) {
                cancelled('filtered', {el: hit.row, key: key});
                return;
            }
            var all = rows();
            var items = model(all);
            var i = all.indexOf(hit.row);
            var end = FT.blockEnd(items, i);
            var target = FT.keyTarget(items, i, key, maxLevels);
            if (target.refused) {
                if (target.refused === 'deep') {
                    markPlaced(hit.row, true);
                }
                cancelled('refused-key', {refused: target.refused, el: hit.row, key: key});
                return;
            }
            var p = FT.parentAt(items, target.at, target.depth, i, end);
            var move = {
                el: hit.row, from: i, at: target.at, depth: target.depth, parentEl: p >= 0 ? all[p] : null,
                index: FT.position(items, target.at, target.depth, i, end), via: 'key', key: key
            };
            var result;
            var h = null;
            // The tag goes in with the move, so the list is laid out once for both.
            flip(function () {
                result = cfg.commit(move);
                var now = target.at > i ? Math.max(i, target.at - (end - i)) : target.at;
                var row = hit.row.isConnected ? hit.row : rows()[now];
                h = row ? row.querySelector(handleSel) : null;
                if (h) {
                    markPlaced(row, false);
                }
            });
            settle(result);
            if (h) {
                if (document.activeElement !== h) {
                    h.focus();
                }
                showHint(h);
            }
        }

        function onFocusIn(e) {
            var hit = rowOf(e.target);
            if (hit && e.target === hit.h && !drag && hit.h.matches(':focus-visible')) {
                showHint(hit.h);
            }
        }

        function onFocusOut(e) {
            if (rowOf(e.target)) {
                hideHint();
            }
        }

        var doc = [
            ['pointermove', onMove], ['pointerup', onUp], ['pointercancel', onCancel], ['keydown', onKeyDoc],
            ['touchmove', onTouchMove, {passive: false}], ['contextmenu', onContext], ['click', onClick, true]
        ];
        var own = [['pointerdown', onDown], ['keydown', onKey], ['focusin', onFocusIn], ['focusout', onFocusOut]];
        doc.forEach(function (l) { document.addEventListener(l[0], l[1], l[2]); });
        own.forEach(function (l) { container.addEventListener(l[0], l[1]); });

        var controller = {
            flip: flip,
            cancel: function () { cancel('escape'); },
            refresh: function () {
                if (drag) {
                    drag.unit = unit();
                    drag.stale = true;
                    update();
                }
            },
            destroy: function () {
                cancel('escape');
                dropPress();
                clearPlaced();
                hint.remove();
                doc.forEach(function (l) { document.removeEventListener(l[0], l[1], l[2]); });
                own.forEach(function (l) { container.removeEventListener(l[0], l[1]); });
                forget();
            }
        };
        Object.defineProperty(controller, 'dragging', {get: function () { return !!drag; }});
        return controller;
    }

    return tree;
})();
