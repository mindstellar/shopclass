/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Appearance > Menus. The menu is held as a flat list with a depth per item; saving nests it
 * and posts it as JSON. An item moves with the items nested under it.
 */
(function () {
    'use strict';

    var root = document.getElementById('menus-page');
    var stateEl = document.getElementById('menu-state');
    if (!root || !stateEl) {
        return;
    }

    var state = JSON.parse(stateEl.textContent);
    var t = state.i18n;
    var maxDepth = state.maxDepth - 1;
    var list = document.getElementById('menu-items');
    var empty = document.getElementById('menu-empty');
    var status = document.getElementById('menu-status');
    var form = document.getElementById('menu-form');
    var json = document.getElementById('menu-items-json');
    var rowTemplate = document.getElementById('menu-row');
    var addError = document.getElementById('menu-add-error');
    var pick = document.getElementById('menu-pick');
    var placeWarn = document.getElementById('menu-place-warn');

    var names = { category: {}, page: {} };
    state.categories.forEach(function (c) { names.category[c.id] = c.name; });
    state.pages.forEach(function (p) { names.page[p.id] = p.name; });

    var items = [];
    var open = null;
    var dirty = false;
    var dragFrom = -1;

    function fmt(text, value) {
        return String(text).replace('%s', value).replace('%d', value);
    }

    function flatten(nested, depth) {
        nested.forEach(function (item) {
            items.push({
                type: item.type,
                ref: item.ref || 0,
                url: item.url || '',
                label: item.label && typeof item.label === 'object' ? item.label : {},
                depth: depth
            });
            if (Array.isArray(item.children)) {
                flatten(item.children, depth + 1);
            }
        });
    }

    function nest() {
        var out = [];
        var parents = [];
        items.forEach(function (item) {
            var row = { type: item.type };
            if (item.type === 'link') {
                row.url = item.url;
            } else {
                row.ref = item.ref;
            }
            var label = {};
            Object.keys(item.label).forEach(function (code) {
                if (String(item.label[code]).trim() !== '') {
                    label[code] = item.label[code];
                }
            });
            row.label = label;
            row.children = [];
            parents.length = item.depth;
            var into = item.depth === 0 ? out : parents[item.depth - 1].children;
            into.push(row);
            parents[item.depth] = row;
        });
        return out;
    }

    function labelOf(item) {
        var own = item.label[state.default] || item.label[Object.keys(item.label)[0]] || '';
        if (own !== '') {
            return own;
        }
        if (item.type === 'link') {
            return item.url;
        }
        return names[item.type][item.ref] || '';
    }

    function blockEnd(i) {
        var end = i + 1;
        while (end < items.length && items[end].depth > items[i].depth) {
            end++;
        }
        return end;
    }

    function clamp() {
        items.forEach(function (item, i) {
            var cap = i === 0 ? 0 : Math.min(items[i - 1].depth + 1, maxDepth);
            if (item.depth > cap) {
                item.depth = cap;
            }
        });
    }

    function changed(focusIndex, focusAct) {
        dirty = true;
        status.textContent = t.unsaved;
        clamp();
        render(focusIndex, focusAct);
    }

    function moveBlock(from, to) {
        var end = blockEnd(from);
        var block = items.splice(from, end - from);
        if (to > from) {
            to -= block.length;
        }
        Array.prototype.splice.apply(items, [to, 0].concat(block));
        return to;
    }

    function moveUp(i) {
        if (i === 0) {
            return i;
        }
        var j = i - 1;
        while (j > 0 && items[j].depth > items[i].depth) {
            j--;
        }
        return moveBlock(i, j);
    }

    function moveDown(i) {
        var end = blockEnd(i);
        if (end >= items.length) {
            return i;
        }
        return moveBlock(i, blockEnd(end));
    }

    function shift(i, by) {
        var end = blockEnd(i);
        for (var k = i; k < end; k++) {
            items[k].depth += by;
        }
    }

    function canIndent(i) {
        if (i === 0 || items[i].depth >= items[i - 1].depth + 1) {
            return false;
        }
        var end = blockEnd(i);
        for (var k = i; k < end; k++) {
            if (items[k].depth + 1 > maxDepth) {
                return false;
            }
        }
        return true;
    }

    function editor(item, box) {
        box.textContent = '';
        var many = state.locales.length > 1;
        state.locales.forEach(function (locale) {
            var id = 'menu-label-' + Math.random().toString(36).slice(2);
            var lab = document.createElement('label');
            lab.className = 'form-sublabel';
            lab.htmlFor = id;
            lab.textContent = many ? fmt(t.labelIn, locale.name) : t.label;
            var input = document.createElement('input');
            input.type = 'text';
            input.id = id;
            input.className = 'input-text';
            input.maxLength = 100;
            input.value = item.label[locale.code] || '';
            input.addEventListener('input', function () {
                item.label[locale.code] = input.value;
                dirty = true;
                status.textContent = t.unsaved;
                box.parentNode.querySelector('.chip-name').textContent = labelOf(item);
            });
            box.appendChild(lab);
            box.appendChild(input);
        });
        if (item.type === 'link') {
            var urlId = 'menu-url-' + Math.random().toString(36).slice(2);
            var ulab = document.createElement('label');
            ulab.className = 'form-sublabel';
            ulab.htmlFor = urlId;
            ulab.textContent = t.url;
            var url = document.createElement('input');
            url.type = 'text';
            url.id = urlId;
            url.className = 'input-text';
            url.value = item.url;
            url.addEventListener('input', function () {
                item.url = url.value.trim();
                dirty = true;
                status.textContent = t.unsaved;
            });
            box.appendChild(ulab);
            box.appendChild(url);
        } else {
            var hint = document.createElement('p');
            hint.className = 'menu-help';
            hint.textContent = t.labelHint;
            box.appendChild(hint);
        }
    }

    function showPlaceWarning() {
        if (!placeWarn || !state.place) {
            return;
        }
        var hidden = items.filter(function (item) { return item.depth >= state.place.depth; }).length;
        placeWarn.hidden = hidden === 0;
        placeWarn.textContent = hidden === 0 ? '' : fmt(hidden === 1 ? t.hidden1 : t.hiddenN, hidden);
    }

    function render(focusIndex, focusAct) {
        showPlaceWarning();
        list.textContent = '';
        empty.hidden = items.length > 0;
        items.forEach(function (item, i) {
            var li = rowTemplate.content.firstElementChild.cloneNode(true);
            var name = labelOf(item);
            var missing = item.type !== 'link' && !names[item.type][item.ref];
            li.style.setProperty('--menu-depth', String(item.depth));
            li.dataset.index = String(i);
            li.classList.toggle('is-missing', missing);
            li.querySelector('.chip-name').textContent = name !== '' ? name : '#' + item.ref;
            li.querySelector('.chip-type').textContent = missing ? t.missing : t[item.type];
            var grip = li.querySelector('.chip-grip');
            grip.setAttribute('aria-label', fmt(t.move, name));
            grip.title = fmt(t.move, name);
            [['outdent', item.depth === 0], ['indent', !canIndent(i)], ['edit', false], ['remove', false]].forEach(function (pair) {
                var btn = li.querySelector('[data-act="' + pair[0] + '"]');
                btn.disabled = pair[1];
                btn.setAttribute('aria-label', fmt(t[pair[0]], name));
                btn.title = fmt(t[pair[0]], name);
            });
            if (open === item) {
                li.classList.add('is-editing');
                var box = li.querySelector('.field-chip-editor');
                box.hidden = false;
                li.querySelector('[data-act="edit"]').setAttribute('aria-expanded', 'true');
                editor(item, box);
            }
            list.appendChild(li);
        });
        if (typeof focusIndex === 'number' && list.children[focusIndex]) {
            var target = list.children[focusIndex].querySelector(focusAct ? '[data-act="' + focusAct + '"]' : '.chip-grip');
            if (target && !target.disabled) {
                target.focus();
            } else {
                list.children[focusIndex].querySelector('.chip-grip').focus();
            }
        }
    }

    function indexOf(el) {
        var li = el.closest('.menu-item');
        return li ? Number(li.dataset.index) : -1;
    }

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        if (!btn) {
            return;
        }
        var i = indexOf(btn);
        var act = btn.dataset.act;
        if (act === 'remove') {
            var end = blockEnd(i);
            for (var k = i + 1; k < end; k++) {
                items[k].depth -= 1;
            }
            items.splice(i, 1);
            changed(Math.min(i, items.length - 1));
        } else if (act === 'indent' && canIndent(i)) {
            shift(i, 1);
            changed(i, 'indent');
        } else if (act === 'outdent' && items[i].depth > 0) {
            shift(i, -1);
            changed(i, 'outdent');
        } else if (act === 'edit') {
            open = open === items[i] ? null : items[i];
            render(i, 'edit');
        }
    });

    list.addEventListener('keydown', function (e) {
        if (!e.target.classList.contains('chip-grip')) {
            return;
        }
        var i = indexOf(e.target);
        var to = i;
        if (e.key === 'ArrowUp') {
            to = moveUp(i);
        } else if (e.key === 'ArrowDown') {
            to = moveDown(i);
        } else if (e.key === 'ArrowRight' && canIndent(i)) {
            shift(i, 1);
        } else if (e.key === 'ArrowLeft' && items[i].depth > 0) {
            shift(i, -1);
        } else {
            return;
        }
        e.preventDefault();
        changed(to);
    });

    list.addEventListener('dragstart', function (e) {
        if (!e.target.classList || !e.target.classList.contains('chip-grip')) {
            return;
        }
        dragFrom = indexOf(e.target);
        var li = e.target.closest('.menu-item');
        li.classList.add('chip-ghost');
        e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(dragFrom));
        if (e.dataTransfer.setDragImage) {
            e.dataTransfer.setDragImage(li, 16, 16);
        }
    });

    list.addEventListener('dragover', function (e) {
        if (dragFrom < 0) {
            return;
        }
        var li = e.target.closest('.menu-item');
        if (!li) {
            return;
        }
        e.preventDefault();
        list.querySelectorAll('.is-drop-before, .is-drop-after').forEach(function (el) {
            el.classList.remove('is-drop-before', 'is-drop-after');
        });
        var box = li.getBoundingClientRect();
        li.classList.add(e.clientY < box.top + box.height / 2 ? 'is-drop-before' : 'is-drop-after');
    });

    list.addEventListener('drop', function (e) {
        var li = e.target.closest('.menu-item');
        if (dragFrom < 0 || !li) {
            return;
        }
        e.preventDefault();
        var target = Number(li.dataset.index);
        var after = li.classList.contains('is-drop-after');
        var end = blockEnd(dragFrom);
        if (target >= dragFrom && target < end) {
            dragFrom = -1;
            render();
            return;
        }
        var depth = items[target].depth;
        var to = after ? blockEnd(target) : target;
        var at = moveBlock(dragFrom, to);
        shift(at, depth - items[at].depth);
        dragFrom = -1;
        changed(at);
    });

    list.addEventListener('dragend', function () {
        dragFrom = -1;
        list.querySelectorAll('.chip-ghost, .is-drop-before, .is-drop-after').forEach(function (el) {
            el.classList.remove('chip-ghost', 'is-drop-before', 'is-drop-after');
        });
    });

    root.querySelectorAll('[data-menu-add]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var type = btn.dataset.menuAdd;
            addError.hidden = true;
            if (items.length >= state.maxItems) {
                addError.textContent = fmt(t.full, state.maxItems);
                addError.hidden = false;
                return;
            }
            var item = { type: type, ref: 0, url: '', label: {}, depth: 0 };
            if (type === 'link') {
                var label = document.getElementById('menu-add-link-label');
                var url = document.getElementById('menu-add-link-url');
                if (label.value.trim() === '' || url.value.trim() === '') {
                    addError.textContent = t.needLink;
                    addError.hidden = false;
                    return;
                }
                if (!/^(https?:\/\/|\/)/i.test(url.value.trim())) {
                    addError.textContent = t.badAddress;
                    addError.hidden = false;
                    url.focus();
                    return;
                }
                item.label[state.default] = label.value.trim();
                item.url = url.value.trim();
                label.value = '';
                url.value = '';
            } else {
                item.ref = Number(document.getElementById('menu-add-' + type).value);
                var already = items.some(function (other) {
                    return other.type === type && Number(other.ref) === item.ref;
                });
                if (already) {
                    addError.textContent = fmt(t.already, names[type][item.ref] || '#' + item.ref);
                    addError.hidden = false;
                    return;
                }
            }
            items.push(item);
            changed();
            status.textContent = t.unsaved;
        });
    });

    if (pick) {
        pick.addEventListener('change', function () {
            pick.form.submit();
        });
    }

    form.addEventListener('submit', function () {
        json.value = JSON.stringify(nest());
        dirty = false;
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = t.leave;
        }
    });

    flatten(state.items, 0);
    clamp();
    render();
    if (state.draft) {
        dirty = true;
        status.textContent = t.unsaved;
    }
}());
