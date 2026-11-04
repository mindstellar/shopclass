/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Reads, fills and watches the config fields of one block (the markup of
 * osc_widget_config_field). Exposed as window.oscWidgetFields for the Customise screen.
 */
(function () {
    'use strict';

    var TYPING = ['text', 'number', 'email', 'url', 'tel', 'search'];
    var states = new WeakMap();

    function nameOf(el) {
        var m = /^config\[(.+)\]$/.exec(el.name || el.getAttribute('name') || '');
        return m ? m[1] : null;
    }

    function controls(scope) {
        return Array.prototype.filter.call(scope.querySelectorAll('[name^="config["]'), function (el) {
            return nameOf(el) !== null;
        });
    }

    function isRich(el) {
        return el.getAttribute('data-osc-richtext') !== null;
    }

    function editorOf(el) {
        var ed = typeof tinymce !== 'undefined' && el.id ? tinymce.get(el.id) : null;
        return ed && ed.initialized ? ed : null;
    }

    function esc(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Text saved before the field was rich: blank lines become paragraphs.
    function paragraphs(value) {
        if (value === '' || /<[a-z]/i.test(value)) {
            return value;
        }
        return value.split(/\n\s*\n/).map(function (part) {
            return '<p>' + esc(part.trim()).replace(/\n/g, '<br>') + '</p>';
        }).join('\n');
    }

    function valueOf(el) {
        if (el.type === 'checkbox') {
            return el.checked ? 1 : 0;
        }
        if (isRich(el)) {
            var ed = editorOf(el);
            return ed ? ed.getContent() : el.value;
        }
        return el.value;
    }

    function read(scope) {
        var out = {};
        controls(scope).forEach(function (el) {
            var name = nameOf(el);
            if (el.type === 'radio') {
                if (el.checked) {
                    out[name] = el.value;
                } else if (!(name in out)) {
                    out[name] = undefined;
                }
                return;
            }
            out[name] = valueOf(el);
        });
        Object.keys(out).forEach(function (k) {
            if (out[k] === undefined) {
                delete out[k];
            }
        });
        return out;
    }

    function applyShowIf(scope) {
        var values = read(scope);
        scope.querySelectorAll('[data-show-name]').forEach(function (box) {
            var m = /^config\[(.+)\]$/.exec(box.getAttribute('data-show-name') || '');
            var held = m && Object.prototype.hasOwnProperty.call(values, m[1]) ? String(values[m[1]]) : null;
            box.hidden = held === null || held !== box.getAttribute('data-show-value');
        });
    }

    function write(scope, config) {
        config = config || {};
        controls(scope).forEach(function (el) {
            var name = nameOf(el);
            if (!Object.prototype.hasOwnProperty.call(config, name)) {
                return;
            }
            var raw = config[name];
            var value = raw === null || raw === undefined ? '' : String(raw);
            if (el.type === 'radio') {
                el.checked = el.value === value;
            } else if (el.type === 'checkbox') {
                el.checked = value !== '' && value !== '0';
            } else if (isRich(el)) {
                var html = paragraphs(value);
                var ed = editorOf(el);
                el.value = html;
                if (ed) {
                    ed.setContent(html);
                }
            } else {
                el.value = value;
                if (el.classList && el.classList.contains('widget-image-input') && window.oscSyncWidgetImage) {
                    window.oscSyncWidgetImage(el);
                }
            }
        });
        applyShowIf(scope);
    }

    function stateOf(scope) {
        var st = states.get(scope);
        if (st) {
            return st;
        }
        st = { handlers: [] };
        states.set(scope, st);

        function emit(el, typing) {
            var name = nameOf(el);
            if (name === null) {
                return;
            }
            var value = valueOf(el);
            st.handlers.forEach(function (fn) {
                fn(name, value, typing);
            });
        }

        scope.addEventListener('input', function (e) {
            var el = e.target;
            if (!el || nameOf(el) === null) {
                return;
            }
            if (el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && TYPING.indexOf(el.type) !== -1)) {
                emit(el, true);
            }
        });
        scope.addEventListener('change', function (e) {
            var el = e.target;
            if (!el || nameOf(el) === null) {
                return;
            }
            applyShowIf(scope);
            emit(el, false);
        });
        return st;
    }

    function mount(scope) {
        var st = stateOf(scope);
        applyShowIf(scope);
        scope.querySelectorAll('.widget-image-input').forEach(function (input) {
            if (window.oscSyncWidgetImage) {
                window.oscSyncWidgetImage(input);
            }
        });
        if (window.oscRichtext) {
            window.oscRichtext.mount(scope, function (editor, el) {
                function fire(typing) {
                    editor.save();
                    var name = nameOf(el);
                    if (name !== null) {
                        st.handlers.forEach(function (fn) {
                            fn(name, editor.getContent(), typing);
                        });
                    }
                }
                editor.on('input', function () { fire(true); });
                editor.on('change', function () { fire(false); });
            });
        }
    }

    function unmount(scope) {
        if (window.oscRichtext) {
            window.oscRichtext.remove(scope);
        }
    }

    function onChange(scope, fn) {
        stateOf(scope).handlers.push(fn);
    }

    window.oscWidgetFields = {
        mount: mount,
        unmount: unmount,
        read: read,
        write: write,
        onChange: onChange
    };
})();
