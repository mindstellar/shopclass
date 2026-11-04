/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Appearance > Customise. Every change goes into the draft (debounced), live look values and
 * plain text reach the preview frame at once by postMessage, and anything else reloads the
 * frame once the draft is saved.
 */
(function () {
    'use strict';

    var root = document.getElementById('cz');
    var stateEl = document.getElementById('cz-state');
    if (!root || !stateEl) {
        return;
    }

    var state = JSON.parse(stateEl.textContent);
    var t = state.i18n;
    var MSG = 'osc-customiser';

    var list = document.getElementById('cz-rows');
    var other = document.getElementById('cz-other');
    var addBtn = document.getElementById('cz-add');
    var typesBox = document.getElementById('cz-tiles');
    var tilesClose = document.getElementById('cz-tiles-close');
    var listView = document.getElementById('cz-list-view');
    var fieldView = document.getElementById('cz-field-view');
    var backBtn = document.getElementById('cz-back');
    var sectionEl = document.getElementById('cz-section');
    var lookSection = document.getElementById('cz-look');
    var lookOpen = document.getElementById('cz-look-open');
    var emptyEl = document.getElementById('cz-empty');
    var sectionTitle = document.getElementById('cz-section-title');
    var sectionType = document.getElementById('cz-section-type');
    var sectionDesc = document.getElementById('cz-section-desc');
    var lockNote = document.getElementById('cz-lock-note');
    var nameInput = document.getElementById('cz-name');
    var removeBtn = document.getElementById('cz-remove');
    var removeText = removeBtn.querySelector('span');
    var sectionFields = document.getElementById('cz-section-fields');
    var look = document.getElementById('cz-look');
    var statusEl = document.getElementById('cz-status');
    var discardBtn = document.getElementById('cz-discard');
    var frameBox = document.getElementById('cz-frame');
    var frame = document.getElementById('cz-preview');
    var pageSelect = document.getElementById('cz-page');
    var template = document.getElementById('cz-type-fields');
    var publishBtn = document.getElementById('cz-publish');

    var rows = {};
    var location_ = '';
    var selected = null;
    var dirtyLocations = {};
    var dirtySettings = {};
    var pendingFiles = {};
    var reloadAfter = false;
    var timer = null;
    var inflight = null;
    var saving = null;
    var retry = null;
    var unsaved = false;
    var publishing = false;

    function fmt(text, value) {
        return String(text).replace('%s', value);
    }

    function copy(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function load(next) {
        state.locations = next.locations;
        state.updated_at = next.updated_at;
        rows = {};
        Object.keys(state.locations).forEach(function (slug) {
            rows[slug] = copy(state.locations[slug].rows);
        });
        if (!rows[location_]) {
            location_ = Object.keys(rows)[0] || '';
        }
        var current = rows[location_] || [];
        if (!current.some(function (r) { return r.ref === selected; })) {
            selected = null;
        }
    }

    // Publish waits for the draft to be saved, so what goes live is what the preview shows.
    function syncPublish() {
        publishBtn.disabled = publishing || unsaved;
    }

    // A draft waiting to be published gets a marked status; with nothing unpublished, Discard has nothing to do.
    function setStatus(text, isError) {
        statusEl.textContent = text;
        if (isError) {
            statusEl.insertAdjacentHTML('afterbegin', '<i class="bi bi-exclamation-circle" aria-hidden="true"></i>');
        }
        statusEl.classList.toggle('is-error', !!isError);
        statusEl.classList.toggle('is-draft', !isError && (text === t.saved || text === t.kept || text === t.saving));
        if (text === t.clean || text === t.published) {
            discardBtn.disabled = true;
        } else if (text === t.saved || text === t.kept) {
            discardBtn.disabled = false;
        }
    }

    function current() {
        return rows[location_] || [];
    }

    function find(ref) {
        var all = current();
        for (var i = 0; i < all.length; i++) {
            if (all[i].ref === ref) {
                return i;
            }
        }
        return -1;
    }

    function labelOf(row) {
        var type = state.types[row.type];
        return row.description || (type ? type.label : (t.legacyName || row.type));
    }

    function selectedName() {
        var i = find(selected);
        return i === -1 ? '' : labelOf(current()[i]);
    }

    function newKey() {
        return 'n' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    /* Preview frame */

    function post(message) {
        message.type = MSG;
        if (frame.contentWindow) {
            frame.contentWindow.postMessage(message, window.location.origin);
        }
    }

    function here() {
        return state.locations[location_] || {preview_url: state.preview_url, other: 0, types: []};
    }

    function reloadFrame() {
        frame.src = here().preview_url;
    }

    window.addEventListener('message', function (e) {
        if (e.origin !== window.location.origin || e.source !== frame.contentWindow) {
            return;
        }
        var data = e.data;
        if (!data || data.type !== MSG) {
            return;
        }
        if (data.ready) {
            post({select: selected, name: selectedName()});
        }
        // The page has no node for that text, so it is drawn by a reload once the draft is saved.
        if (data.missed) {
            changed(true, location_);
        }
        if (data.picked !== undefined && data.picked !== null) {
            var ref = String(data.picked);
            if (find(ref) !== -1) {
                openRow(ref, false);
                var item = list.querySelector('[data-ref="' + CSS.escape(ref) + '"]');
                if (item) {
                    item.scrollIntoView({block: 'nearest'});
                }
            }
        }
    });

    /* Block list */

    function icon(name) {
        return '<i class="bi bi-' + name + '" aria-hidden="true"></i>';
    }

    function button(cls, act, label, iconName, extra) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = cls;
        b.setAttribute('data-act', act);
        b.setAttribute('aria-label', label);
        b.title = label;
        b.innerHTML = icon(iconName);
        Object.keys(extra || {}).forEach(function (k) {
            b.setAttribute(k, extra[k]);
        });
        return b;
    }

    function renderList(focus) {
        var all = current();
        list.textContent = '';
        all.forEach(function (row) {
            var name = labelOf(row);
            var li = document.createElement('li');
            li.className = 'cz-row' + (row.ref === selected ? ' is-selected' : '') + (row.enabled ? '' : ' is-off') + (row.locked ? ' is-locked' : '');
            li.setAttribute('data-ref', row.ref);

            li.appendChild(button('cz-grip', 'grip', fmt(t.move, name), 'grip-vertical', {'aria-describedby': 'cz-grip-help'}));

            var type = state.types[row.type];
            var ticon = document.createElement('span');
            ticon.className = 'cz-ticon';
            ticon.innerHTML = row.locked ? icon('lock') : (type && type.icon ? icon(type.icon) : '');
            li.appendChild(ticon);

            var nm = document.createElement('button');
            nm.type = 'button';
            nm.className = 'cz-name';
            nm.setAttribute('data-act', 'pick');
            nm.setAttribute('aria-current', row.ref === selected ? 'true' : 'false');
            nm.textContent = name;
            if (!row.enabled) {
                var off = document.createElement('span');
                off.className = 'visually-hidden';
                off.textContent = ' (' + t.hidden + ')';
                nm.appendChild(off);
            }
            li.appendChild(nm);

            var acts = document.createElement('span');
            acts.className = 'cz-acts';
            var eye = button('cz-hide', 'eye', fmt(row.enabled ? t.hide : t.show, name), row.enabled ? 'eye' : 'eye-slash',
                {'aria-pressed': row.enabled ? 'false' : 'true'});
            eye.disabled = !!row.locked;
            acts.appendChild(eye);
            li.appendChild(acts);
            list.appendChild(li);
        });
        emptyEl.hidden = all.length !== 0;

        var count = here().other || 0;
        other.hidden = count === 0;
        other.textContent = count ? other.getAttribute('data-text').replace('%d', count) : '';

        if (focus) {
            var target = list.querySelector('[data-ref="' + CSS.escape(focus.ref) + '"] [data-act="' + focus.act + '"]');
            if (target && target.disabled) {
                target = list.querySelector('[data-ref="' + CSS.escape(focus.ref) + '"] [data-act="grip"]');
            }
            if (target) {
                target.focus();
            }
        }
    }

    function move(ref, delta, act) {
        var all = current();
        var i = find(ref);
        var j = i + delta;
        if (i === -1 || j < 0 || j >= all.length) {
            return;
        }
        var row = all.splice(i, 1)[0];
        all.splice(j, 0, row);
        renderList({ref: ref, act: act});
        structural();
    }

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-act]');
        var li = btn ? btn.closest('[data-ref]') : null;
        if (!li) {
            return;
        }
        var ref = li.getAttribute('data-ref');
        var act = btn.getAttribute('data-act');
        var i = find(ref);
        if (i === -1) {
            return;
        }
        if (act === 'pick') {
            openRow(ref, true);
        } else if (act === 'eye' && !current()[i].locked) {
            current()[i].enabled = !current()[i].enabled;
            renderList({ref: ref, act: 'eye'});
            structural();
        }
    });

    list.addEventListener('keydown', function (e) {
        var grip = e.target.closest('.cz-grip');
        if (!grip || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) {
            return;
        }
        e.preventDefault();
        move(grip.closest('[data-ref]').getAttribute('data-ref'), e.key === 'ArrowUp' ? -1 : 1, 'grip');
    });

    /* Sliding between the list and the field view */

    var view = 'list';
    var mounted = null;
    var armTimer = null;

    function slide(to) {
        view = to;
        var inField = to !== 'list';
        listView.classList.toggle('is-out-back', inField);
        listView.inert = inField;
        fieldView.classList.toggle('is-out-fwd', !inField);
        fieldView.inert = !inField;
    }

    function unmountFields() {
        if (mounted) {
            window.oscWidgetFields.unmount(mounted);
            mounted = null;
        }
    }

    function disarm() {
        clearTimeout(armTimer);
        if (removeBtn.classList.contains('is-armed')) {
            removeBtn.classList.remove('is-armed');
            removeText.textContent = t.removeText || t.remove;
            var i = find(selected);
            if (i !== -1) {
                removeBtn.setAttribute('aria-label', fmt(t.remove, labelOf(current()[i])));
            }
        }
    }

    function canRemove(row) {
        return !row.locked || (!!row.legacy && !state.moderator);
    }

    function addNote(text) {
        var p = document.createElement('p');
        p.className = 'cz-hint';
        p.textContent = text;
        sectionFields.appendChild(p);
    }

    function renderPanel() {
        disarm();
        unmountFields();
        sectionFields.textContent = '';
        var i = find(selected);
        if (i === -1) {
            return;
        }
        var row = current()[i];
        var type = state.types[row.type];
        sectionTitle.textContent = labelOf(row);
        sectionType.textContent = type ? type.label : '';
        sectionDesc.textContent = type ? type.description : '';
        lockNote.hidden = !row.locked;
        lockNote.querySelector('span').textContent = row.locked ? (row.legacy ? t.lockLegacy : t.lockSuper) : '';
        nameInput.value = type && row.description === type.label ? '' : (row.description || '');
        nameInput.placeholder = type ? type.label : (t.legacyName || '');
        nameInput.disabled = !!row.locked;
        removeBtn.hidden = !canRemove(row);
        removeBtn.setAttribute('aria-label', fmt(t.remove, labelOf(row)));

        var fieldset = row.legacy ? null : fieldsetFor(row.type);
        if (!fieldset) {
            if (!row.legacy) {
                addNote(t.noFields);
            }
            return;
        }
        fieldset.hidden = false;
        sectionFields.appendChild(fieldset);
        var fields = window.oscWidgetFields;
        fields.write(fieldset, row.config);
        if (row.locked) {
            fieldset.querySelectorAll('input, select, textarea, button').forEach(function (el) {
                el.disabled = true;
            });
            fieldset.querySelectorAll('[data-osc-richtext]').forEach(function (el) {
                el.removeAttribute('data-osc-richtext');
            });
        }
        mounted = fieldset;
        fields.mount(fieldset);
        fields.onChange(fieldset, function (name, value, typing) {
            if (row.locked) {
                return;
            }
            if (String(row.config[name]) === String(value) && !typing) {
                return;
            }
            row.config[name] = value;
            var control = fieldset.querySelector('[name="config[' + CSS.escape(name) + ']"]');
            var rich = control && control.getAttribute('data-osc-richtext') !== null;
            if (typing && !rich) {
                post({text: {section: row.ref, field: name, value: value}});
                changed(false, location_);
            } else {
                changed(true, location_);
            }
        });
    }

    function openRow(ref, focusPanel) {
        if (find(ref) === -1) {
            return;
        }
        closeTypes();
        selected = ref;
        if (lookSection) {
            lookSection.hidden = true;
        }
        sectionEl.hidden = false;
        sectionTitle.hidden = false;
        sectionType.hidden = false;
        renderList();
        renderPanel();
        slide('field');
        post({select: ref, name: selectedName()});
        if (focusPanel) {
            var first = sectionFields.querySelector('input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled)');
            (first || backBtn).focus();
        }
    }

    function openLook() {
        closeTypes();
        sectionEl.hidden = true;
        sectionTitle.hidden = true;
        sectionType.hidden = true;
        lookSection.hidden = false;
        slide('look');
        backBtn.focus();
    }

    function closeField(focusRow) {
        var ref = selected;
        unmountFields();
        slide('list');
        if (focusRow) {
            var target = ref ? list.querySelector('[data-ref="' + CSS.escape(ref) + '"] [data-act="pick"]') : null;
            (target || (lookOpen && view === 'list' && !ref ? lookOpen : addBtn)).focus();
        }
    }

    backBtn.addEventListener('click', function () {
        closeField(true);
    });
    if (lookOpen) {
        lookOpen.addEventListener('click', openLook);
    }
    fieldView.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !e.defaultPrevented && view !== 'list' && !e.target.closest('dialog, .tox-tinymce-aux')) {
            e.preventDefault();
            closeField(true);
        }
    });

    nameInput.addEventListener('input', function () {
        var i = find(selected);
        if (i === -1 || current()[i].locked) {
            return;
        }
        var row = current()[i];
        var type = state.types[row.type];
        row.description = nameInput.value.trim() || (type ? type.label : row.description);
        sectionTitle.textContent = labelOf(row);
        var item = list.querySelector('[data-ref="' + CSS.escape(row.ref) + '"] .cz-name');
        if (item) {
            item.firstChild.textContent = labelOf(row);
        }
        post({rename: {ref: row.ref, name: labelOf(row)}});
        changed(false, location_);
    });

    removeBtn.addEventListener('focusout', disarm);
    removeBtn.addEventListener('click', function () {
        var i = find(selected);
        if (i === -1 || !canRemove(current()[i])) {
            return;
        }
        if (!removeBtn.classList.contains('is-armed')) {
            removeBtn.classList.add('is-armed');
            removeBtn.setAttribute('aria-label', fmt(t.removeConfirm || t.remove, labelOf(current()[i])));
            removeText.textContent = t.removeShort || t.remove;
            armTimer = setTimeout(disarm, 4000);
            return;
        }
        clearTimeout(armTimer);
        var all = current();
        all.splice(i, 1);
        selected = null;
        unmountFields();
        slide('list');
        renderList();
        post({select: null});
        (all.length ? list.querySelector('[data-act="pick"]') : addBtn).focus();
        structural();
    });

    /* Adding a block */

    function closeTypes() {
        typesBox.hidden = true;
        addBtn.setAttribute('aria-expanded', 'false');
    }

    addBtn.addEventListener('click', function () {
        var open = typesBox.hidden;
        typesBox.hidden = !open;
        addBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var allowed = here().types;
            typesBox.querySelectorAll('[data-add]').forEach(function (b) {
                b.hidden = allowed.indexOf(b.getAttribute('data-add')) === -1;
            });
            typesBox.querySelectorAll('[data-group]').forEach(function (g) {
                g.hidden = !g.querySelector('[data-add]:not([hidden])');
            });
            var first = typesBox.querySelector('[data-add]:not([hidden])');
            if (first) {
                first.focus();
            }
        }
    });

    if (tilesClose) {
        tilesClose.addEventListener('click', function () {
            closeTypes();
            addBtn.focus();
        });
    }

    typesBox.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-add]');
        if (!btn) {
            return;
        }
        var type = btn.getAttribute('data-add');
        var fieldset = fieldsetFor(type);
        var key = newKey();
        current().push({
            ref: key,
            id: null,
            key: key,
            type: type,
            description: state.types[type] ? state.types[type].label : type,
            enabled: true,
            locked: false,
            legacy: false,
            config: fieldset ? window.oscWidgetFields.read(fieldset) : {}
        });
        openRow(key, true);
        structural();
    });

    typesBox.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closeTypes();
            addBtn.focus();
        }
    });

    function fieldsetFor(type) {
        var fs = template.content.querySelector('[data-type-id="' + CSS.escape(type) + '"]');
        return fs ? fs.cloneNode(true) : null;
    }

    /* Look fields */

    function lookValue(box) {
        var name = box.getAttribute('data-look');
        var type = box.getAttribute('data-look-type');
        var control = box.querySelector('[name="' + CSS.escape(name) + '"]');
        if (type === 'radio') {
            control = box.querySelector('[name="' + CSS.escape(name) + '"]:checked');
            return control ? control.value : '';
        }
        if (type === 'checkbox') {
            return control && control.checked ? '1' : '0';
        }
        return control ? control.value : '';
    }

    function onLook(e) {
        var box = e.target.closest('[data-look]');
        if (!box) {
            return;
        }
        var name = box.getAttribute('data-look');
        var type = box.getAttribute('data-look-type');
        if (type === 'image') {
            if (e.type !== 'change') {
                return;
            }
            var file = box.querySelector('input[type="file"]');
            var remove = box.querySelector('input[type="checkbox"]');
            var preview = box.querySelector('.field-image-preview');
            if (e.target === file && file.files.length) {
                pendingFiles[name] = file.files[0];
                delete dirtySettings[name];
                if (remove) {
                    remove.checked = false;
                }
                if (preview) {
                    preview.src = URL.createObjectURL(file.files[0]);
                    preview.hidden = false;
                }
                changed(true);
            } else if (e.target === remove) {
                if (remove.checked) {
                    dirtySettings[name] = '';
                    delete pendingFiles[name];
                    file.value = '';
                    if (preview) {
                        preview.hidden = true;
                    }
                    changed(true);
                } else {
                    delete dirtySettings[name];
                    if (preview) {
                        preview.hidden = false;
                    }
                }
            }
            return;
        }
        var typing = e.type === 'input';
        if (typing && type !== 'text' && type !== 'textarea' && type !== 'email' && type !== 'url' && type !== 'tel') {
            return;
        }
        var value = lookValue(box);
        if (dirtySettings[name] === value) {
            return;
        }
        dirtySettings[name] = value;
        box.querySelectorAll('.cz-swatch').forEach(function (s) {
            s.setAttribute('aria-pressed', s.getAttribute('data-value') === value ? 'true' : 'false');
        });
        if (state.live[name]) {
            post({set: {attr: state.live[name], value: value}});
            changed(false);
        } else {
            changed(true);
        }
    }

    if (look) {
        look.addEventListener('input', onLook);
        look.addEventListener('change', onLook);
        look.addEventListener('click', function (e) {
            var swatch = e.target.closest('.cz-swatch');
            if (!swatch) {
                return;
            }
            var box = swatch.closest('[data-look]');
            var select_ = box.querySelector('select');
            if (select_ && select_.value !== swatch.getAttribute('data-value')) {
                select_.value = swatch.getAttribute('data-value');
                select_.dispatchEvent(new Event('change', {bubbles: true}));
            }
        });
    }

    /* Saving the draft */

    function structural() {
        changed(true, location_);
    }

    function changed(reload, slug) {
        if (slug) {
            dirtyLocations[slug] = true;
        }
        if (reload) {
            reloadAfter = true;
        }
        if (retry) {
            clearTimeout(retry);
            retry = null;
        }
        if (inflight) {
            inflight.abort();
        }
        clearTimeout(timer);
        unsaved = true;
        syncPublish();
        setStatus(t.saving);
        timer = setTimeout(save, 600);
    }

    function appendRows(fd, slug) {
        var all = rows[slug];
        if (!all.length) {
            fd.append('locations[' + slug + ']', '');
            return;
        }
        all.forEach(function (row, i) {
            var base = 'locations[' + slug + '][' + i + ']';
            if (row.id) {
                fd.append(base + '[id]', String(row.id));
            }
            fd.append(base + '[key]', row.key || '');
            fd.append(base + '[type]', row.type);
            fd.append(base + '[description]', row.description || '');
            fd.append(base + '[enabled]', row.enabled ? '1' : '0');
            Object.keys(row.config).forEach(function (name) {
                var value = row.config[name];
                fd.append(base + '[config][' + name + ']', value === null || value === undefined ? '' : String(value));
            });
        });
    }

    function save() {
        timer = null;
        var fd = new FormData();
        var sentLocations = dirtyLocations;
        var sentSettings = dirtySettings;
        var sentFiles = {};
        dirtyLocations = {};
        dirtySettings = {};
        Object.keys(sentLocations).forEach(function (slug) {
            appendRows(fd, slug);
        });
        Object.keys(sentSettings).forEach(function (name) {
            fd.append('settings[' + name + ']', sentSettings[name]);
        });
        Object.keys(pendingFiles).forEach(function (name) {
            sentFiles[name] = pendingFiles[name];
            fd.append(name, pendingFiles[name]);
        });

        var controller = new AbortController();
        inflight = controller;
        setStatus(t.saving);

        function putBack() {
            Object.keys(sentLocations).forEach(function (slug) {
                dirtyLocations[slug] = true;
            });
            Object.keys(sentSettings).forEach(function (name) {
                if (!Object.prototype.hasOwnProperty.call(dirtySettings, name)) {
                    dirtySettings[name] = sentSettings[name];
                }
            });
        }

        saving = fetch(state.urls.draft, {method: 'POST', body: fd, credentials: 'same-origin', signal: controller.signal})
            .then(function (res) {
                return res.json().catch(function () {
                    return {error: 1};
                }).then(function (json) {
                    return {status: res.status, json: json};
                });
            })
            .then(function (r) {
                if (inflight === controller) {
                    inflight = null;
                }
                if (r.status === 200 && r.json && r.json.error === 0) {
                    Object.keys(sentFiles).forEach(function (name) {
                        if (pendingFiles[name] === sentFiles[name]) {
                            delete pendingFiles[name];
                            var input = look && look.querySelector('[data-look="' + CSS.escape(name) + '"] input[type="file"]');
                            if (input) {
                                input.value = '';
                            }
                        }
                    });
                    state.updated_at = r.json.saved_at;
                    if (!timer && !retry) {
                        unsaved = false;
                        syncPublish();
                    }
                    setStatus(t.saved);
                    if (reloadAfter && !timer) {
                        reloadAfter = false;
                        reloadFrame();
                    }
                    return true;
                }
                putBack();
                if (r.status >= 400 && r.status < 500) {
                    setStatus((r.json && r.json.errors && r.json.errors[0]) || t.failed, true);
                    return false;
                }
                throw new Error('save failed');
            })
            .catch(function () {
                if (controller.signal.aborted) {
                    putBack();
                    return false;
                }
                if (inflight === controller) {
                    inflight = null;
                }
                putBack();
                setStatus(t.retrying, true);
                retry = setTimeout(function () {
                    retry = null;
                    save();
                }, 3000);
                return false;
            });
        return saving;
    }

    function flush() {
        if (retry) {
            clearTimeout(retry);
            retry = null;
            return save();
        }
        if (timer) {
            clearTimeout(timer);
            return save();
        }
        return inflight && saving ? saving : Promise.resolve(true);
    }

    window.addEventListener('beforeunload', function (e) {
        if (timer || inflight) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    /* Publish and discard */

    function postAction(url) {
        return fetch(url, {method: 'POST', credentials: 'same-origin'}).then(function (res) {
            return res.json().catch(function () {
                return {error: 1};
            }).then(function (json) {
                return {status: res.status, json: json};
            });
        });
    }

    function message(kind, text) {
        if (typeof window.setJsMessage === 'function') {
            window.setJsMessage(kind, text);
        }
    }

    function refresh() {
        return fetch(window.location.href, {credentials: 'same-origin'}).then(function (res) {
            return res.text();
        }).then(function (html) {
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var el = doc.getElementById('cz-state');
            if (el) {
                load(JSON.parse(el.textContent));
                renderList();
                if (selected) {
                    renderPanel();
                } else if (view === 'field') {
                    closeField(false);
                }
            }
        });
    }

    var staleDialog = document.getElementById('cz-stale-dialog');
    var staleConfirm = document.getElementById('cz-stale-confirm');

    function publish(force) {
        publishing = true;
        syncPublish();
        flush().then(function (ok) {
            if (ok === false) {
                return null;
            }
            setStatus(t.publishing);
            return postAction(state.urls.publish + (force ? '&force=1' : '')).then(function (r) {
                if (r.status === 200 && r.json.error === 0) {
                    var note = document.getElementById('cz-stale');
                    if (note) {
                        note.remove();
                    }
                    setStatus(t.published);
                    message('ok', t.publishedOk);
                    return refresh().then(reloadFrame);
                }
                if (r.status === 409 && r.json && r.json.stale && staleDialog && typeof staleDialog.showModal === 'function') {
                    setStatus(t.kept);
                    staleDialog.showModal();
                    return null;
                }
                var text = (r.json && r.json.errors && r.json.errors[0]) || t.failed;
                setStatus(text, r.status !== 409);
                message(r.status === 409 ? 'info' : 'error', text);
                return null;
            });
        }).catch(function () {
            setStatus(t.failed, true);
            message('error', t.failed);
        }).then(function () {
            publishing = false;
            syncPublish();
        });
    }

    publishBtn.addEventListener('click', function () {
        publish(false);
    });
    if (staleConfirm) {
        staleConfirm.addEventListener('click', function () {
            staleDialog.close();
            publish(true);
        });
    }

    var discardConfirm = document.getElementById('cz-discard-confirm');
    discardConfirm.addEventListener('click', function () {
        discardConfirm.disabled = true;
        clearTimeout(timer);
        timer = null;
        if (inflight) {
            inflight.abort();
        }
        postAction(state.urls.discard).then(function (r) {
            if (r.status === 200 && r.json.error === 0) {
                window.location.reload();
                return;
            }
            discardConfirm.disabled = false;
            message('error', t.failed);
        }, function () {
            discardConfirm.disabled = false;
            message('error', t.failed);
        });
    });

    /* Page and device */

    if (pageSelect) {
        pageSelect.addEventListener('change', function () {
            location_ = pageSelect.value;
            selected = null;
            closeTypes();
            closeField(false);
            renderList();
            reloadFrame();
        });
    }

    root.querySelectorAll('[data-device]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var phone = btn.getAttribute('data-device') === 'phone';
            frameBox.classList.toggle('is-phone', phone);
            root.querySelectorAll('[data-device]').forEach(function (b) {
                b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
            });
        });
    });

    load(state);
    location_ = (pageSelect && pageSelect.value) || location_;
    renderList();
    // The frame may have loaded before this script ran, so its ready message was missed.
    frame.addEventListener('load', function () {
        post({select: selected, name: selectedName()});
    });
    post({select: selected, name: selectedName()});
    setStatus(state.updated_at > 0 ? t.kept : t.clean);
})();
