/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The editing screens' shared behaviour: mounting every rich-text field, keeping the
 * locale tab strips of one form on the same locale, opening whatever hides a rejected
 * field so its message can be read, and driving the pickers, the expiry reveal and the
 * confirm a destructive state change asks for.
 */
(function () {
    'use strict';

    // Every editor this module mounted, so the theme toggle can re-mount them.
    var mounted = [];

    // Mount one editor per <textarea data-osc-richtext>. The configuration is the
    // server's, so no screen writes an editor setup of its own and none of them has to
    // find its editors by a name pattern.
    function mountRichtext() {
        var fields = document.querySelectorAll('textarea[data-osc-richtext]');
        if (!fields.length || typeof tinymce === 'undefined') {
            return;
        }

        fields.forEach(function (field) {
            var cfg;
            try {
                cfg = JSON.parse(field.getAttribute('data-osc-richtext') || '{}');
            } catch (e) {
                return;
            }

            // Two keys the browser has to turn into callbacks: JSON cannot carry a
            // function, so the server sends the signed endpoint and the intent.
            var media = cfg.osc_media === true;
            var uploadUrl = cfg.osc_upload_url || '';
            delete cfg.osc_media;
            delete cfg.osc_upload_url;

            cfg.target = field;
            delete cfg.selector;

            if (media && uploadUrl) {
                cfg.automatic_uploads = true;
                cfg.images_upload_credentials = true;
                cfg.images_upload_url = uploadUrl;
                cfg.file_picker_types = 'image';
                cfg.file_picker_callback = function (cb, value, meta) {
                    if (meta.filetype !== 'image' || !window.oscMediaPicker) {
                        return;
                    }
                    window.oscMediaPicker.open(function (url) {
                        cb(url, { title: '' });
                    });
                };
            }

            if (window.oscTinymceTheme) {
                Object.assign(cfg, window.oscTinymceTheme());
            }
            mounted.push(cfg);
            tinymce.init(cfg);
        });
    }

    // The theme toggle rewrites data-bs-theme in place and never reloads, but an editor's
    // skin and content stylesheet are chosen once at init -- so a light admin kept a dark
    // editor until the next page load. Re-mounting is the only way to change them; remove()
    // writes the content back to the textarea and init() reads it again, so nothing is lost.
    function followTheme() {
        if (typeof tinymce === 'undefined' || !window.oscTinymceTheme) {
            return;
        }
        mounted.forEach(function (cfg) {
            var editor = cfg.target && tinymce.get(cfg.target.id);
            if (!editor) {
                return;
            }
            editor.remove();
            Object.assign(cfg, window.oscTinymceTheme());
            tinymce.init(cfg);
        });
    }

    // Every translated field in one form shows the same locale. Each is its own widget, and
    // a field drawn without a strip of its own has no other way to be switched — so a click
    // on any strip moves every field to the locale in the same position. Without it a title
    // in one language can sit above a body in another, and nothing on screen says so.
    function syncLocaleTabs(clicked) {
        var strip = clicked.closest('.field-translate .osc-tab');
        var field = clicked.closest('.field-translate');
        var form = clicked.closest('form');
        if (!strip || !form) {
            return;
        }
        var links = Array.prototype.slice.call(strip.querySelectorAll(':scope > ul > li > a'));
        var index = links.indexOf(clicked);
        if (index < 0) {
            return;
        }

        form.querySelectorAll('.field-translate').forEach(function (other) {
            if (other === field) {
                return;
            }
            var panels = other.querySelectorAll(':scope > .field-translate-panel');
            if (panels.length !== links.length) {
                return;
            }
            panels.forEach(function (panel, i) {
                panel.hidden = i !== index;
            });
            other.querySelectorAll(':scope > .osc-tab > ul > li > a').forEach(function (link, i) {
                var on = i === index;
                link.parentNode.classList.toggle('is-active', on);
                link.setAttribute('aria-selected', on ? 'true' : 'false');
                link.tabIndex = on ? 0 : -1;
            });
        });
    }

    // A rejected field inside a closed disclosure or behind an unopened locale tab is an
    // error nobody can see. Open what holds the first one, so the summary's link lands on
    // a visible control.
    function revealErrors() {
        var first = document.querySelector('[aria-invalid="true"]');
        if (!first) {
            return;
        }

        var details = first.closest('details');
        while (details) {
            details.open = true;
            details = details.parentElement ? details.parentElement.closest('details') : null;
        }

        var panel = first.closest('.field-translate-panel');
        if (panel && panel.id) {
            var tab = document.querySelector('.osc-tab a[href="#' + CSS.escape(panel.id) + '"]');
            if (tab) {
                tab.click();
            }
        }
    }


    // ---------------------------------------------------------------------
    // The category picker. One control showing the chosen path, a searchable
    // list behind it, and the hidden field the save reads -- which is also
    // where the plugin-field loader and the price show/hide listen, so a pick
    // has to fire `change` on it.
    // ---------------------------------------------------------------------
    function initCategoryPickers() {
        document.querySelectorAll('[data-osc-catpick]').forEach(function (root) {
            if (root.getAttribute('data-osc-catpick-init')) {
                return;
            }
            root.setAttribute('data-osc-catpick-init', '1');

            var hidden = root.querySelector('input[type="hidden"]');
            var button = root.querySelector('.osc-catpick-value');
            var pop = root.querySelector('.osc-catpick-pop');
            var search = root.querySelector('.osc-catpick-search');
            var list = root.querySelector('.osc-catpick-list');
            var empty = root.querySelector('.osc-catpick-empty');
            if (!hidden || !button || !pop || !list) {
                return;
            }
            var options = Array.prototype.slice.call(list.querySelectorAll('.osc-catpick-option'));
            var active = -1;

            function shown() {
                return options.filter(function (o) { return !o.hidden; });
            }

            function place() {
                var r = button.getBoundingClientRect();
                var below = window.innerHeight - r.bottom;
                pop.style.left = r.left + 'px';
                pop.style.width = r.width + 'px';
                // Above the control when there is more room there: a list that runs off the
                // bottom of a short viewport cannot be scrolled to.
                if (below < 200 && r.top > below) {
                    pop.style.top = '';
                    pop.style.bottom = (window.innerHeight - r.top + 2) + 'px';
                } else {
                    pop.style.bottom = '';
                    pop.style.top = (r.bottom + 2) + 'px';
                }
            }

            function highlight(i) {
                var list2 = shown();
                options.forEach(function (o) { o.classList.remove('is-active'); });
                active = i;
                if (i >= 0 && list2[i]) {
                    list2[i].classList.add('is-active');
                    list2[i].scrollIntoView({ block: 'nearest' });
                    button.setAttribute('aria-activedescendant', list2[i].id);
                } else {
                    button.removeAttribute('aria-activedescendant');
                }
            }

            function open() {
                pop.hidden = false;
                button.setAttribute('aria-expanded', 'true');
                place();
                if (search) {
                    search.value = '';
                    filter();
                    search.focus();
                }
                var chosen = shown().findIndex(function (o) {
                    return o.getAttribute('aria-selected') === 'true';
                });
                highlight(chosen < 0 ? 0 : chosen);
            }

            function close(focusButton) {
                pop.hidden = true;
                button.setAttribute('aria-expanded', 'false');
                button.removeAttribute('aria-activedescendant');
                if (focusButton) {
                    button.focus();
                }
            }

            function paint(path) {
                var target = root.querySelector('[data-osc-catpick-path]');
                if (!target) {
                    return;
                }
                target.textContent = '';
                path.split(' \u203a ').forEach(function (step, i) {
                    if (i > 0) {
                        var sep = document.createElement('span');
                        sep.className = 'osc-catpick-sep';
                        sep.setAttribute('aria-hidden', 'true');
                        sep.textContent = '\u203a';
                        target.appendChild(sep);
                    }
                    target.appendChild(document.createTextNode(step));
                });
            }

            function choose(option) {
                if (!option) {
                    return;
                }
                options.forEach(function (o) { o.setAttribute('aria-selected', 'false'); });
                option.setAttribute('aria-selected', 'true');
                hidden.value = option.getAttribute('data-value');
                paint(option.getAttribute('data-path') || '');
                button.classList.remove('is-invalid');
                close(true);
                hidden.dispatchEvent(new Event('change', { bubbles: true }));
            }

            function filter() {
                var term = (search ? search.value : '').trim().toLowerCase();
                var hits = 0;
                options.forEach(function (o) {
                    var match = term === ''
                        || (o.getAttribute('data-path') || '').toLowerCase().indexOf(term) !== -1;
                    o.hidden = !match;
                    if (match) {
                        hits++;
                    }
                });
                if (empty) {
                    empty.hidden = hits > 0;
                }
                highlight(hits > 0 ? 0 : -1);
            }

            function move(step) {
                var list2 = shown();
                if (!list2.length) {
                    return;
                }
                highlight(Math.max(0, Math.min(list2.length - 1, active + step)));
            }

            button.addEventListener('click', function () {
                if (pop.hidden) { open(); } else { close(false); }
            });
            list.addEventListener('mousedown', function (e) {
                var option = e.target.closest('.osc-catpick-option');
                if (option) {
                    e.preventDefault();
                    choose(option);
                }
            });
            if (search) {
                search.addEventListener('input', filter);
                search.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        move(1);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        move(-1);
                    } else if (e.key === 'Enter') {
                        e.preventDefault();
                        choose(shown()[active]);
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        close(true);
                    }
                });
            }
            document.addEventListener('click', function (e) {
                if (!pop.hidden && !root.contains(e.target)) {
                    close(false);
                }
            });
            window.addEventListener('scroll', function () { if (!pop.hidden) { place(); } }, true);
            window.addEventListener('resize', function () { if (!pop.hidden) { place(); } });

            // The form's own validator marks the hidden field, which nobody can see. Carry
            // the mark to the control that is on screen.
            var form = root.closest('form');
            if (form) {
                form.addEventListener('submit', function () {
                    button.classList.toggle('is-invalid', hidden.classList.contains('is-invalid'));
                });
            }
        });
    }

    // ---------------------------------------------------------------------
    // The user picker: picking a registered user fills the contact fields the
    // save actually reads.
    // ---------------------------------------------------------------------
    function initUserPickers() {
        if (typeof oscAutocomplete !== 'function') {
            return;
        }
        document.querySelectorAll('input[data-osc-user-search]').forEach(function (input) {
            var fields;
            try {
                fields = JSON.parse(input.getAttribute('data-osc-user-fields') || '{}');
            } catch (e) {
                fields = {};
            }
            var form = input.closest('form');

            function fill(name, value) {
                if (!name || !form || value == null) {
                    return;
                }
                var field = form.querySelector('[name="' + name + '"]');
                if (!field) {
                    return;
                }
                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }

            oscAutocomplete(input, {
                source: input.getAttribute('data-osc-user-source') || '',
                minLength: 2,
                onSelect: function (item) {
                    if (!item || item.id === '') {
                        return false;
                    }
                    fill(fields.name, item.value);
                    // The endpoint labels a user "Name (e-mail)"; the e-mail is what the
                    // save matches on, so it is read from its own key where there is one.
                    var email = item.email;
                    if (!email) {
                        var m = String(item.label || '').match(/\(([^()]+)\)\s*$/);
                        email = m ? m[1] : '';
                    }
                    fill(fields.email, email);
                    input.value = '';

                    return false;
                }
            });
        });
    }

    // ---------------------------------------------------------------------
    // Expiry: the status panel says when a record expires; Change reveals the
    // field that moves it. An empty box would reset the date to the category's
    // default, so closing it -- or leaving it empty -- puts back the value that
    // means "leave it alone".
    // ---------------------------------------------------------------------
    function initExpiry() {
        var toggle = document.querySelector('[data-osc-expiry-toggle]');
        var box = document.querySelector('[data-osc-expiry]');
        if (!toggle || !box) {
            return;
        }
        var field = box.querySelector('[data-osc-expiry-field]');
        var keep = field ? field.value : '';

        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            box.hidden = !box.hidden;
            toggle.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
            if (!field) {
                return;
            }
            if (box.hidden) {
                field.value = keep;
            } else {
                if (field.value === keep) {
                    field.value = '';
                }
                field.focus();
            }
        });

        var never = box.querySelector('[data-osc-expiry-never]');
        if (never && field) {
            never.addEventListener('change', function () {
                field.value = never.checked ? '0' : '';
                field.readOnly = never.checked;
            });
        }

        var form = box.closest('form');
        if (form && field) {
            form.addEventListener('submit', function () {
                if (field.value.trim() === '') {
                    field.value = keep;
                }
            });
        }
    }

    // ---------------------------------------------------------------------
    // The photo grid: a tile per photo, staged by the upload endpoint and
    // carried to the save in the ajax_photos[] input the tile holds. The first
    // tile is the cover, and can be chosen while no photo is attached yet.
    // ---------------------------------------------------------------------
    function initPhotoGrids() {
        document.querySelectorAll('[data-osc-photos]').forEach(mountPhotoGrid);
    }

    function mountPhotoGrid(root) {
        var grid = root.querySelector('[data-osc-photo-grid]');
        var add = root.querySelector('[data-osc-photo-add]');
        var count = root.querySelector('[data-osc-photo-count]');
        if (!grid || !add) {
            return;
        }

        var input = add.querySelector('input[type="file"]');
        var max = parseInt(root.getAttribute('data-max'), 10) || 0;
        var maxSize = parseInt(root.getAttribute('data-max-size'), 10) || 0;
        var pickCover = root.getAttribute('data-cover') === '1';
        var extensions = (root.getAttribute('data-extensions') || '').split(',').filter(Boolean);
        var text = {};
        try {
            text = JSON.parse(root.getAttribute('data-strings') || '{}');
        } catch (e) {
            text = {};
        }

        function say(key, map) {
            var s = text[key] || '';

            return s.replace(/\{(\w+)\}/g, function (whole, k) {
                return map && map[k] != null ? map[k] : whole;
            });
        }

        function tiles() {
            return Array.prototype.slice.call(grid.querySelectorAll('[data-osc-photo]'));
        }

        // A tile holding a refusal is a message, not a photo: it posts nothing, so it is
        // neither counted nor eligible to be the cover.
        function photos() {
            return tiles().filter(function (tile) { return !tile.classList.contains('is-error'); });
        }

        function icon(name) {
            var i = document.createElement('i');
            i.className = 'bi ' + name;
            i.setAttribute('aria-hidden', 'true');

            return i;
        }

        function button(cls, iconName, label) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = cls;
            b.title = label;
            b.setAttribute('aria-label', label);
            b.appendChild(icon(iconName));

            return b;
        }

        // The badge, the make-cover control and the count line all follow from the order
        // the tiles are in, so one pass after every change keeps them from disagreeing.
        function refresh() {
            var all = photos();
            all.forEach(function (tile, index) {
                var label = tile.getAttribute('data-label') || '';
                var badge = tile.querySelector('.osc-photo-cover');
                var make = tile.querySelector('[data-osc-photo-cover]');
                if (index === 0) {
                    if (make) {
                        make.remove();
                    }
                    if (!badge) {
                        badge = document.createElement('span');
                        badge.className = 'osc-photo-cover';
                        badge.textContent = text.cover || 'Cover';
                        tile.appendChild(badge);
                    }

                    return;
                }
                if (badge) {
                    badge.remove();
                }
                if (pickCover && !make) {
                    make = button('osc-photo-make-cover', 'bi-star', say('coverOf', { file: label }));
                    make.setAttribute('data-osc-photo-cover', '');
                    make.title = text.makeCover || '';
                    tile.appendChild(make);
                }
            });

            if (count) {
                count.textContent = max > 0
                    ? say('countMax', { n: all.length, max: max })
                    : say('count', { n: all.length });
            }
            add.hidden = max > 0 && all.length >= max;
        }

        function reject(name, message) {
            var tile = document.createElement('div');
            tile.className = 'osc-photo is-error';
            tile.setAttribute('role', 'listitem');
            tile.setAttribute('data-osc-photo', '');
            tile.setAttribute('data-label', name);
            var body = document.createElement('p');
            body.className = 'osc-photo-msg';
            body.setAttribute('role', 'alert');
            body.textContent = message;
            tile.appendChild(body);
            var remove = button('osc-photo-remove', 'bi-x-lg', say('removeOf', { file: name }));
            remove.setAttribute('data-osc-photo-remove', '');
            tile.appendChild(remove);
            var caption = document.createElement('span');
            caption.className = 'osc-photo-name';
            caption.textContent = name;
            tile.appendChild(caption);
            grid.insertBefore(tile, add);
            refresh();
        }

        function extensionOf(name) {
            var dot = name.lastIndexOf('.');

            return dot < 0 ? '' : name.slice(dot + 1).toLowerCase();
        }

        function accept(file) {
            if (extensions.length && extensions.indexOf(extensionOf(file.name)) < 0) {
                return say('type', { file: file.name });
            }
            if (maxSize && file.size > maxSize) {
                return say('size', { file: file.name });
            }

            return null;
        }

        function upload(file) {
            var tile = document.createElement('div');
            tile.className = 'osc-photo is-uploading';
            tile.setAttribute('role', 'listitem');
            tile.setAttribute('data-osc-photo', '');
            tile.setAttribute('data-label', file.name);

            var img = document.createElement('img');
            img.className = 'osc-photo-img';
            img.alt = '';
            var preview = URL.createObjectURL(file);
            img.src = preview;
            tile.appendChild(img);

            var bar = document.createElement('span');
            bar.className = 'osc-photo-bar';
            var fill = document.createElement('span');
            bar.appendChild(fill);
            tile.appendChild(bar);

            var remove = button('osc-photo-remove', 'bi-x-lg', say('removeOf', { file: file.name }));
            remove.setAttribute('data-osc-photo-remove', '');
            tile.appendChild(remove);

            var caption = document.createElement('span');
            caption.className = 'osc-photo-name';
            caption.textContent = file.name;
            tile.appendChild(caption);

            grid.insertBefore(tile, add);
            refresh();

            var body = new FormData();
            body.append('qqfile', file);
            body.append('qquuid', window.crypto && window.crypto.randomUUID
                ? window.crypto.randomUUID()
                : String(Date.now()) + '-' + String(Math.random()).slice(2));

            var xhr = new XMLHttpRequest();
            xhr.open('POST', root.getAttribute('data-upload-url'), true);
            xhr.withCredentials = true;
            if (xhr.upload) {
                xhr.upload.addEventListener('progress', function (e) {
                    if (e.lengthComputable) {
                        fill.style.inlineSize = Math.round((e.loaded / e.total) * 100) + '%';
                    }
                });
            }
            xhr.addEventListener('loadend', function () {
                URL.revokeObjectURL(preview);
                bar.remove();
                var answer = null;
                try {
                    answer = JSON.parse(xhr.responseText);
                } catch (e) {
                    answer = null;
                }
                if (xhr.status !== 200 || !answer || answer.success !== true || !answer.uploadName) {
                    tile.classList.remove('is-uploading');
                    tile.classList.add('is-error');
                    img.remove();
                    var badge = tile.querySelector('.osc-photo-cover');
                    if (badge) {
                        badge.remove();
                    }
                    var failed = document.createElement('p');
                    failed.className = 'osc-photo-msg';
                    failed.setAttribute('role', 'alert');
                    failed.textContent = say('failed', { file: file.name });
                    tile.insertBefore(failed, tile.firstChild);
                    refresh();

                    return;
                }

                tile.classList.remove('is-uploading');
                tile.setAttribute('data-temp', answer.uploadName);
                img.src = root.getAttribute('data-temp-url') + encodeURIComponent(answer.uploadName);
                var carry = document.createElement('input');
                carry.type = 'hidden';
                carry.name = 'ajax_photos[]';
                carry.value = answer.uploadName;
                tile.appendChild(carry);
                refresh();
            });
            xhr.send(body);
        }

        function take(files) {
            for (var i = 0; i < files.length; i++) {
                if (max > 0 && photos().length >= max) {
                    reject(files[i].name, text.tooMany || '');
                    continue;
                }
                var refusal = accept(files[i]);
                if (refusal) {
                    reject(files[i].name, refusal);
                    continue;
                }
                upload(files[i]);
            }
        }

        function drop(tile) {
            // A tile with nothing behind it -- a refused file, or an upload that never
            // finished -- is only on screen, so there is nothing to ask the server about.
            var query = null;
            if (tile.getAttribute('data-temp')) {
                query = 'ajax_photo=' + encodeURIComponent(tile.getAttribute('data-temp'));
            } else if (tile.getAttribute('data-id')) {
                query = 'id=' + encodeURIComponent(tile.getAttribute('data-id'))
                    + '&item=' + encodeURIComponent(tile.getAttribute('data-item'))
                    + '&code=' + encodeURIComponent(tile.getAttribute('data-code'))
                    + '&secret=' + encodeURIComponent(tile.getAttribute('data-secret'));
            }
            if (query === null) {
                tile.remove();
                refresh();

                return;
            }
            if (!window.confirm(text.confirm || '')) {
                return;
            }

            var url = root.getAttribute('data-delete-url');
            fetch(url + (url.indexOf('?') > -1 ? '&' : '?') + query, { credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (answer) {
                    if (answer && answer.success === false) {
                        throw new Error('refused');
                    }
                    tile.remove();
                    refresh();
                })
                .catch(function () {
                    tile.classList.add('is-error');
                    var failed = tile.querySelector('.osc-photo-msg') || document.createElement('p');
                    failed.className = 'osc-photo-msg';
                    failed.setAttribute('role', 'alert');
                    failed.textContent = text.gone || '';
                    tile.insertBefore(failed, tile.firstChild);
                });
        }

        input.addEventListener('change', function () {
            take(input.files);
            // The same files must not be posted a second time by the field itself.
            input.value = '';
        });

        root.addEventListener('click', function (e) {
            var tile = e.target.closest ? e.target.closest('[data-osc-photo]') : null;
            if (!tile) {
                return;
            }
            if (e.target.closest('[data-osc-photo-remove]')) {
                e.preventDefault();
                drop(tile);

                return;
            }
            if (e.target.closest('[data-osc-photo-cover]')) {
                e.preventDefault();
                grid.insertBefore(tile, grid.firstChild);
                refresh();
            }
        });

        ['dragenter', 'dragover'].forEach(function (name) {
            root.addEventListener(name, function (e) {
                e.preventDefault();
                add.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            root.addEventListener(name, function (e) {
                e.preventDefault();
                add.classList.remove('is-dragover');
            });
        });
        root.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                take(e.dataTransfer.files);
            }
        });

        refresh();
    }

    // ---------------------------------------------------------------------
    // A destructive state change asks first, and says what it does. One dialog,
    // built on demand from the link that opens it.
    // ---------------------------------------------------------------------
    function initConfirms() {
        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('[data-osc-confirm]') : null;
            if (!link || link.getAttribute('data-osc-confirmed')) {
                return;
            }
            e.preventDefault();

            var dialog = document.createElement('dialog');
            dialog.className = 'osc-dialog osc-dialog-danger';
            var body = document.createElement('div');
            body.className = 'osc-dialog-body';
            var title = document.createElement('p');
            title.className = 'osc-dialog-title';
            var icon = document.createElement('i');
            icon.className = 'bi bi-exclamation-triangle';
            icon.setAttribute('aria-hidden', 'true');
            title.appendChild(icon);
            title.appendChild(document.createTextNode(
                link.getAttribute('data-osc-confirm-title') || link.textContent.trim()
            ));
            var text = document.createElement('p');
            text.className = 'osc-dialog-text';
            text.textContent = link.getAttribute('data-osc-confirm');
            body.appendChild(title);
            body.appendChild(text);

            var actions = document.createElement('div');
            actions.className = 'osc-dialog-actions';
            var cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.className = 'btn btn-sm btn-dim';
            cancel.textContent = link.getAttribute('data-osc-confirm-cancel') || 'Cancel';
            var go = document.createElement('button');
            go.type = 'button';
            go.className = 'btn btn-sm btn-danger';
            go.textContent = link.getAttribute('data-osc-confirm-label') || link.textContent.trim();
            actions.appendChild(cancel);
            actions.appendChild(go);
            body.appendChild(actions);
            dialog.appendChild(body);
            document.body.appendChild(dialog);

            dialog.addEventListener('close', function () { dialog.remove(); });
            cancel.addEventListener('click', function () { dialog.close(); });
            go.addEventListener('click', function () {
                link.setAttribute('data-osc-confirmed', '1');
                dialog.close();
                link.click();
            });
            dialog.showModal();
            go.focus();
        });
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('.field-translate .osc-tab a') : null;
        if (link) {
            syncLocaleTabs(link);
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        mountRichtext();
        revealErrors();
        initCategoryPickers();
        initUserPickers();
        initPhotoGrids();
        initExpiry();
        initConfirms();

        new MutationObserver(function (records) {
            for (var i = 0; i < records.length; i++) {
                if (records[i].attributeName === 'data-bs-theme') {
                    followTheme();

                    return;
                }
            }
        }).observe(document.documentElement, { attributes: true });
    });
})();
