/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * The listing form's option editor: option names with their values as chips, and a table
 * with one row per combination. Rows are plain inputs posted with the form; the server
 * checks them.
 */
(function () {
    'use strict';

    var FIELDS = ['sku', 'price', 'compareAt', 'stock', 'weight'];
    var MODES = {sku: '', price: 'decimal', compareAt: 'decimal', stock: 'numeric', weight: 'numeric'};

    function init() {
        var editor = document.querySelector('[data-variant-editor]');
        var toggle = document.querySelector('[data-variant-switch]');
        if (!editor || !toggle) {
            return;
        }
        var plain = document.querySelectorAll('[data-variant-default]');
        var withOptions = document.querySelectorAll('[data-variant-with-options]');
        var optionsBox = editor.querySelector('[data-variant-options]');
        var head = editor.querySelector('[data-variant-head]');
        var body = editor.querySelector('[data-variant-rows]');
        var note = editor.querySelector('[data-variant-note]');
        var noteText = note ? note.textContent : '';
        var max = {
            options: parseInt(editor.getAttribute('data-max-options'), 10) || 3,
            values: parseInt(editor.getAttribute('data-max-values'), 10) || 30,
            rows: parseInt(editor.getAttribute('data-max-rows'), 10) || 100,
            name: parseInt(editor.getAttribute('data-max-name'), 10) || 30,
            value: parseInt(editor.getAttribute('data-max-value'), 10) || 40
        };
        var i18n = {};
        var photos = {};
        try {
            i18n = JSON.parse(editor.getAttribute('data-i18n') || '{}');
            photos = JSON.parse(editor.getAttribute('data-photos') || '{}');
        } catch {
            i18n = {};
        }

        function el(tag, attrs, text) {
            var node = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (key) {
                node.setAttribute(key, attrs[key]);
            });
            if (text !== undefined) {
                node.textContent = text;
            }
            return node;
        }

        function clean(text) {
            return String(text).replace(/\s+/g, ' ').trim();
        }

        function options() {
            return Array.prototype.slice.call(optionsBox.querySelectorAll('[data-option]'));
        }

        function chips(option) {
            return Array.prototype.map.call(option.querySelectorAll('[data-chip]'), function (chip) {
                return chip.getAttribute('data-chip');
            });
        }

        function rows() {
            return Array.prototype.slice.call(body.querySelectorAll('[data-row]'));
        }

        function say(text) {
            if (note) {
                note.textContent = text || noteText;
            }
        }

        // Every input's name follows its place, so removing or adding rows and options never
        // leaves a gap the server would read as a missing value.
        function renumber() {
            options().forEach(function (option, k) {
                option.querySelector('[data-option-name]').name = 'product[optionNames][' + k + ']';
            });
            rows().forEach(function (row, n) {
                var base = 'product[variants][' + n + ']';
                row.querySelectorAll('[data-option-cell] [data-field="options"]').forEach(function (input, k) {
                    input.name = base + '[options][' + k + ']';
                });
                row.querySelectorAll('[data-field]:not([data-field="options"])').forEach(function (input) {
                    input.name = base + '[' + input.getAttribute('data-field') + ']';
                });
            });
            editor.querySelector('[data-option-add]').disabled = options().length >= max.options;
        }

        function syncHead() {
            head.querySelectorAll('[data-option-head]').forEach(function (th) {
                th.remove();
            });
            var first = head.firstChild;
            options().forEach(function (option) {
                var name = clean(option.querySelector('[data-option-name]').value);
                head.insertBefore(el('th', {scope: 'col', 'data-option-head': ''}, name), first);
            });
        }

        function addChip(option, value) {
            value = clean(value).slice(0, max.value);
            var list = chips(option);
            if (value === '' || list.indexOf(value) !== -1 || list.length >= max.values) {
                return;
            }
            var chip = el('span', {'class': 'osc-variant-chip', 'data-chip': value}, value);
            var label = (i18n.removeValue || 'Remove %s').replace('%s', value);
            chip.appendChild(el('button', {type: 'button', 'class': 'osc-variant-chip-x', 'aria-label': label, 'data-chip-remove': ''}, '×'));
            option.querySelector('[data-chips]').insertBefore(chip, option.querySelector('[data-chip-input]'));
        }

        function addOption() {
            if (options().length >= max.options) {
                return null;
            }
            var option = el('div', {'class': 'osc-variant-option', 'data-option': ''});
            var name = el('input', {type: 'text', 'class': 'form-control', maxlength: String(max.name), 'data-option-name': ''});
            name.setAttribute('aria-label', i18n.optionName || '');
            name.setAttribute('placeholder', i18n.optionName || '');
            var box = el('div', {'class': 'osc-variant-chips', 'data-chips': ''});
            var input = el('input', {type: 'text', 'class': 'osc-variant-chip-input', maxlength: String(max.value), 'data-chip-input': ''});
            input.setAttribute('aria-label', i18n.valueInput || '');
            input.setAttribute('placeholder', i18n.valueInput || '');
            box.appendChild(input);
            option.appendChild(name);
            option.appendChild(box);
            option.appendChild(el('button', {type: 'button', 'class': 'btn btn-dim btn-sm', 'data-option-remove': ''}, i18n.removeOpt || 'Remove'));
            optionsBox.appendChild(option);
            // Existing rows gain an empty cell, which Generate fills.
            rows().forEach(function (row) {
                var cells = row.querySelectorAll('[data-option-cell]');
                var at = cells.length ? cells[cells.length - 1].nextSibling : row.firstChild;
                row.insertBefore(optionCell(''), at);
            });
            syncHead();
            renumber();
            name.focus();
            return option;
        }

        function optionCell(value) {
            var td = el('td', {'data-option-cell': ''});
            var hidden = el('input', {type: 'hidden', 'data-field': 'options'});
            hidden.value = value;
            td.appendChild(hidden);
            td.appendChild(el('span', {'data-option-text': ''}, value === '' ? '—' : value));
            return td;
        }

        function makeRow(values) {
            var row = el('tr', {'data-row': ''});
            values.forEach(function (value) {
                row.appendChild(optionCell(value));
            });
            FIELDS.forEach(function (field) {
                var td = el('td');
                var input = el('input', {type: 'text', 'class': 'form-control form-control-sm', autocomplete: 'off', 'data-field': field});
                if (field === 'sku') {
                    input.setAttribute('maxlength', '64');
                }
                if (MODES[field]) {
                    input.setAttribute('inputmode', MODES[field]);
                }
                td.appendChild(input);
                row.appendChild(td);
            });
            var td = el('td');
            var ship = el('input', {type: 'hidden', 'data-field': 'shippingClass'});
            td.appendChild(ship);
            var select = el('select', {'class': 'form-select form-select-sm', 'data-field': 'photoId'});
            select.appendChild(el('option', {value: ''}, i18n.none || ''));
            Object.keys(photos).forEach(function (id) {
                select.appendChild(el('option', {value: id}, photos[id]));
            });
            td.appendChild(select);
            row.appendChild(td);
            var last = el('td');
            last.appendChild(el('button', {type: 'button', 'class': 'btn btn-dim btn-sm', 'data-row-remove': ''}, i18n.remove || 'Remove'));
            row.appendChild(last);
            return row;
        }

        function rowKey(row) {
            return Array.prototype.map.call(row.querySelectorAll('[data-option-cell] [data-field="options"]'), function (input) {
                return input.value;
            }).join('\u0000');
        }

        // One row per combination of the chips. A row already there for a combination is kept
        // with what was typed in it; rows for combinations that no longer exist go.
        function generate() {
            var lists = options().map(chips);
            if (lists.length === 0 || lists.some(function (list) { return list.length === 0; })
                || options().some(function (option) { return clean(option.querySelector('[data-option-name]').value) === ''; })) {
                say(i18n.noValues);
                return;
            }
            var total = lists.reduce(function (n, list) { return n * list.length; }, 1);
            if (total > max.rows) {
                say((i18n.tooMany || '%d').replace('%d', String(total)));
                return;
            }
            var combos = [[]];
            lists.forEach(function (list) {
                var next = [];
                combos.forEach(function (combo) {
                    list.forEach(function (value) {
                        next.push(combo.concat([value]));
                    });
                });
                combos = next;
            });
            var kept = {};
            rows().forEach(function (row) {
                kept[rowKey(row)] = row;
            });
            body.querySelectorAll('[data-row-error]').forEach(function (row) {
                row.remove();
            });
            var fresh = document.createDocumentFragment();
            combos.forEach(function (combo) {
                var key = combo.join('\u0000');
                fresh.appendChild(kept[key] || makeRow(combo));
                delete kept[key];
            });
            body.textContent = '';
            body.appendChild(fresh);
            say('');
            renumber();
        }

        function show() {
            editor.hidden = !toggle.checked;
            plain.forEach(function (block) {
                block.hidden = toggle.checked;
            });
            withOptions.forEach(function (block) {
                block.hidden = !toggle.checked;
            });
            if (toggle.checked && options().length === 0) {
                addOption();
            }
        }

        toggle.addEventListener('change', show);

        editor.addEventListener('click', function (event) {
            var target = event.target;
            if (target.closest('[data-option-add]')) {
                addOption();
            } else if (target.closest('[data-generate]')) {
                generate();
            } else if (target.closest('[data-chip-remove]')) {
                target.closest('[data-chip]').remove();
            } else if (target.closest('[data-option-remove]')) {
                var option = target.closest('[data-option]');
                var k = options().indexOf(option);
                option.remove();
                rows().forEach(function (row) {
                    var cell = row.querySelectorAll('[data-option-cell]')[k];
                    if (cell) {
                        cell.remove();
                    }
                });
                syncHead();
                renumber();
            } else if (target.closest('[data-row-remove]')) {
                var row = target.closest('[data-row]');
                var error = row.nextElementSibling;
                if (error && error.hasAttribute('data-row-error')) {
                    error.remove();
                }
                row.remove();
                renumber();
            }
        });

        editor.addEventListener('keydown', function (event) {
            var input = event.target.closest('[data-chip-input]');
            if (!input) {
                return;
            }
            if (event.key === 'Enter' || event.key === ',') {
                event.preventDefault();
                addChip(input.closest('[data-option]'), input.value);
                input.value = '';
            } else if (event.key === 'Backspace' && input.value === '') {
                var list = input.closest('[data-option]').querySelectorAll('[data-chip]');
                if (list.length) {
                    list[list.length - 1].remove();
                }
            }
        });

        // A value typed but not yet added still counts once the field is left.
        editor.addEventListener('focusout', function (event) {
            var input = event.target.closest('[data-chip-input]');
            if (input && input.value.trim() !== '') {
                addChip(input.closest('[data-option]'), input.value);
                input.value = '';
            }
        });

        editor.addEventListener('input', function (event) {
            if (event.target.closest('[data-option-name]')) {
                syncHead();
            }
        });

        renumber();
        show();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
