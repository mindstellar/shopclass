/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Appearance > Menus: the menu is one flat list with a depth per item, indented by depth, and an
 * item moves with the items nested under it. Drag and keys run on osc.sortable's tree mode; saving
 * nests the list and posts it as JSON.
 */
/* global osc */
(function () {
    'use strict';

    var root = document.getElementById('menus-page');
    var stateEl = document.getElementById('menu-state');
    if (!root || !stateEl) {
        return;
    }

    var state = JSON.parse(stateEl.textContent);
    var t = state.i18n;
    var list = document.getElementById('menu-items');
    var empty = document.getElementById('menu-empty');
    var status = document.getElementById('menu-status');
    var form = document.getElementById('menu-form');
    var json = document.getElementById('menu-items-json');
    var rowTemplate = document.getElementById('menu-row');
    var addError = document.getElementById('menu-add-error');
    var pick = document.getElementById('menu-pick');
    var placeWarn = document.getElementById('menu-place-warn');
    var announce = document.getElementById('menu-announce');

    var names = { category: {}, page: {}, builtin: {} };
    var builtinOn = {};
    state.categories.forEach(function (c) { names.category[c.id] = c.name; });
    state.pages.forEach(function (p) { names.page[p.id] = p.name; });
    state.builtins.forEach(function (b) {
        names.builtin[b.id] = b.name;
        builtinOn[b.id] = b.on;
    });

    var items = [];
    var open = null;
    var dirty = false;

    function fmt(text, value) {
        return String(text).replace('%s', value).replace('%d', value);
    }

    // The server's rule in short: an http(s) address or a site path, no spaces or quotes.
    function goodUrl(value) {
        return /^(https?:\/\/[^\s"<>\\]+|\/(?![/\\])[^\s"<>\\]*)$/i.test(String(value).trim());
    }

    function hasLabel(item) {
        return Object.keys(item.label).some(function (code) {
            return String(item.label[code]).trim() !== '';
        });
    }

    // What is wrong with an item, '' when nothing.
    function problem(item) {
        if (item.type === 'link') {
            return goodUrl(item.url) && hasLabel(item) ? '' : t.badLink;
        }
        if (item.type === 'builtin' && names.builtin[item.ref] && !builtinOn[item.ref]) {
            return t.builtinOff;
        }
        return names[item.type][item.ref] ? '' : t.missing;
    }

    function markRow(li, item) {
        var bad = problem(item);
        li.classList.toggle('is-missing', bad !== '');
        li.querySelector('.chip-type').textContent = bad !== '' ? bad : t[item.type];
    }

    function position(i) {
        var at = osc.flatTree.siblingIndex(items, i);
        return String(t.position).replace('%1$d', at.index).replace('%2$d', at.count).replace('%3$d', items[i].depth + 1);
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

    var FT = osc.flatTree;

    function changed(focusIndex, focusAct) {
        dirty = true;
        status.textContent = t.unsaved;
        FT.clamp(items, state.maxDepth);
        render(focusIndex, focusAct);
    }

    function settle(i) {
        var row = list.querySelector('.menu-item[data-index="' + i + '"]');
        if (row) {
            row.classList.add('is-settled');
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    function canIndent(i) {
        return !FT.keyTarget(items, i, 'in', state.maxDepth).refused;
    }

    // The row for an item is kept between draws, so a move can slide the rows instead of redrawing them.
    var rowsOf = new WeakMap();

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
                markRow(box.parentNode, item);
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
            var urlError = document.createElement('p');
            urlError.className = 'menu-add-error';
            urlError.id = urlId + '-error';
            url.setAttribute('aria-describedby', urlError.id);
            var checkUrl = function () {
                var bad = !goodUrl(url.value);
                urlError.hidden = !bad;
                urlError.textContent = bad ? t.badAddress : '';
                url.setAttribute('aria-invalid', bad ? 'true' : 'false');
            };
            url.addEventListener('input', function () {
                item.url = url.value.trim();
                dirty = true;
                status.textContent = t.unsaved;
                checkUrl();
                markRow(box.parentNode, item);
                if (!hasLabel(item)) {
                    box.parentNode.querySelector('.chip-name').textContent = labelOf(item);
                }
            });
            checkUrl();
            box.appendChild(ulab);
            box.appendChild(url);
            box.appendChild(urlError);
        } else {
            var hint = document.createElement('p');
            hint.className = 'menu-help';
            hint.textContent = t.labelHint;
            box.appendChild(hint);
        }
    }

    var warnChanged = false;

    // What a move says: the new position, plus the hidden-items warning when the move changed it.
    function moved(at) {
        say(position(at) + (warnChanged && placeWarn && !placeWarn.hidden ? '. ' + placeWarn.textContent : ''));
    }

    function showPlaceWarning() {
        if (!placeWarn || !state.place) {
            return;
        }
        var hidden = items.filter(function (item) { return item.depth >= state.place.depth; }).length;
        var text = hidden === 0 ? '' : fmt(hidden === 1 ? t.hidden1 : t.hiddenN, hidden);
        warnChanged = text !== placeWarn.textContent;
        placeWarn.hidden = hidden === 0;
        placeWarn.textContent = text;
    }

    function render(focusIndex, focusAct) {
        showPlaceWarning();
        empty.hidden = items.length > 0;
        var lis = items.map(function (item, i) {
            var li = rowsOf.get(item);
            if (!li) {
                li = rowTemplate.content.firstElementChild.cloneNode(true);
                rowsOf.set(item, li);
            }
            var name = labelOf(item);
            li._item = item;
            li.dataset.index = String(i);
            li.dataset.depth = String(item.depth);
            li.style.setProperty('--tree-d', String(item.depth));
            li.querySelector('.chip-name').textContent = name !== '' ? name : '#' + item.ref;
            markRow(li, item);
            var grip = li.querySelector('.chip-grip');
            grip.setAttribute('aria-label', fmt(t.move, name));
            grip.title = fmt(t.move, name);
            [['outdent', item.depth === 0], ['indent', !canIndent(i)], ['edit', false], ['remove', false]].forEach(function (pair) {
                var btn = li.querySelector('[data-act="' + pair[0] + '"]');
                btn.disabled = pair[1];
                btn.setAttribute('aria-label', fmt(t[pair[0]], name));
                btn.title = fmt(t[pair[0]], name);
            });
            var box = li.querySelector('.field-chip-editor');
            var editing = open === item;
            li.classList.toggle('is-editing', editing);
            li.querySelector('[data-act="edit"]').setAttribute('aria-expanded', editing ? 'true' : 'false');
            box.hidden = !editing;
            if (editing) {
                editor(item, box);
            } else {
                box.textContent = '';
            }
            return li;
        });
        list.replaceChildren.apply(list, lis);
        var row = typeof focusIndex === 'number' ? list.querySelector('.menu-item[data-index="' + focusIndex + '"]') : null;
        if (row) {
            var target = row.querySelector(focusAct ? '[data-act="' + focusAct + '"]' : '.chip-grip');
            if (target && !target.disabled) {
                target.focus();
            } else {
                row.querySelector('.chip-grip').focus();
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
            var end = FT.blockEnd(items, i);
            for (var k = i + 1; k < end; k++) {
                items[k].depth -= 1;
            }
            items.splice(i, 1);
            changed(Math.min(i, items.length - 1));
            if (items.length === 0) {
                var firstAdd = root.querySelector('[data-menu-add]:not(:disabled)');
                if (firstAdd) {
                    firstAdd.focus();
                }
            }
        } else if (act === 'indent' || act === 'outdent') {
            var target = FT.keyTarget(items, i, act === 'indent' ? 'in' : 'out', state.maxDepth);
            if (!target.refused) {
                var to = FT.moveBlock(items, i, target.at, target.depth);
                changed(to, act);
                moved(to);
            }
        } else if (act === 'edit') {
            open = open === items[i] ? null : items[i];
            render(i, 'edit');
        }
    });

    function say(text) {
        osc.announce(announce, text);
    }

    function labelFor(parentEl) {
        return parentEl ? fmt(t.inside, labelOf(parentEl._item)) : t.topLevel;
    }

    var refusals = {
        first: 'isFirst',
        last: 'isLast',
        top: 'isTop',
        'nothing-above': 'nothingAbove'
    };

    osc.sortable(list, {
        handle: '.chip-grip',
        tree: {
            rows: 'li.menu-item',
            depth: function (el) { return el._item.depth; },
            indent: null,
            maxLevels: state.maxDepth,
            label: labelFor,
            tooDeep: fmt(t.tooDeep, state.maxDepth),
            ghost: function (el, below) {
                return {
                    name: labelOf(el._item),
                    more: below > 0 ? fmt(osc.plural(t.more, below), below) : ''
                };
            },
            keys: true,
            hint: [t.hintMove, t.hintIn, t.hintOut],
            commit: function (move) {
                var at = FT.moveBlock(items, move.from, move.at, move.depth);
                changed(at);
                moved(at);
            },
            cancelled: function (reason, info) {
                if (reason === 'refused-key' && info) {
                    var name = labelOf(info.el._item);
                    say(fmt(t[info.refused === 'deep' ? 'tooDeep' : refusals[info.refused]], info.refused === 'deep' ? state.maxDepth : name));
                } else if (reason === 'deep') {
                    say(fmt(t.tooDeep, state.maxDepth));
                }
            }
        }
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
                if (!goodUrl(url.value)) {
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
                var picked = document.getElementById('menu-add-' + type).value;
                item.ref = type === 'builtin' ? picked : Number(picked);
                var already = items.some(function (other) {
                    return other.type === type && String(other.ref) === String(item.ref);
                });
                if (already) {
                    addError.textContent = fmt(t.already, names[type][item.ref] || '#' + item.ref);
                    addError.hidden = false;
                    return;
                }
            }
            items.push(item);
            changed();
            settle(items.length - 1);
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

    // Deleting throws the edits away anyway; the leave-page warning would stack on the confirm.
    var deleteForm = document.querySelector('#menuDeleteDialog form');
    if (deleteForm) {
        deleteForm.addEventListener('submit', function () {
            dirty = false;
        });
    }

    // A new menu's id follows its name until the owner types one.
    var newName = document.getElementById('menu-new-label');
    var newId = document.getElementById('menu-new-id');
    var advanced = document.getElementById('menu-new-advanced');
    if (newName && newId) {
        var idTouched = false;
        newId.addEventListener('input', function () {
            idTouched = newId.value !== '';
        });
        newName.addEventListener('input', function () {
            if (!idTouched) {
                newId.value = newName.value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
                    .replace(/[^a-z0-9_-]+/g, '-')
                    .replace(/-{2,}/g, '-')
                    .replace(/^[^a-z]+/, '')
                    .slice(0, 32)
                    .replace(/-+$/, '');
            }
        });
        newId.addEventListener('invalid', function () {
            if (advanced) {
                advanced.open = true;
            }
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (dirty) {
            e.preventDefault();
            e.returnValue = t.leave;
        }
    });

    flatten(state.items, 0);
    FT.clamp(items, state.maxDepth);
    render();
    if (state.draft) {
        dirty = true;
        status.textContent = t.unsaved;
    }
}());
