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
    var typesBox = document.getElementById('cz-types');
    var sectionTitle = document.getElementById('cz-section-title');
    var sectionFields = document.getElementById('cz-section-fields');
    var look = document.getElementById('cz-look');
    var statusEl = document.getElementById('cz-status');
    var frameBox = document.getElementById('cz-frame');
    var frame = document.getElementById('cz-preview');
    var pageSelect = document.getElementById('cz-page');
    var template = document.getElementById('cz-type-fields');

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
            selected = current.length ? current[0].ref : null;
        }
    }

    function setStatus(text, isError) {
        statusEl.textContent = text;
        statusEl.classList.toggle('is-error', !!isError);
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
        return row.description || (type ? type.label : row.type);
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

    function reloadFrame() {
        frame.src = state.locations[location_].preview_url;
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
            post({select: selected});
        }
        if (data.picked !== undefined && data.picked !== null) {
            var ref = String(data.picked);
            if (find(ref) !== -1) {
                select(ref, false);
                var item = list.querySelector('[data-ref="' + CSS.escape(ref) + '"]');
                if (item) {
                    item.scrollIntoView({block: 'nearest'});
                }
            }
        }
    });

    /* Section list */

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
        all.forEach(function (row, i) {
            var name = labelOf(row);
            var li = document.createElement('li');
            li.className = 'cz-row' + (row.ref === selected ? ' is-selected' : '') + (row.enabled ? '' : ' is-off');
            li.setAttribute('data-ref', row.ref);

            li.appendChild(button('cz-grip', 'grip', fmt(t.move, name), 'grip-vertical', {'aria-describedby': 'cz-grip-help'}));

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

            var up = button('cz-mini', 'up', fmt(t.moveUp, name), 'chevron-up');
            up.disabled = i === 0;
            var down = button('cz-mini', 'down', fmt(t.moveDown, name), 'chevron-down');
            down.disabled = i === all.length - 1;
            var eye = button('cz-mini', 'eye', fmt(row.enabled ? t.hide : t.show, name), row.enabled ? 'eye' : 'eye-slash',
                {'aria-pressed': row.enabled ? 'false' : 'true'});
            var rm = button('cz-mini', 'remove', fmt(t.remove, name), 'x-lg');
            li.appendChild(up);
            li.appendChild(down);
            li.appendChild(eye);
            li.appendChild(rm);
            list.appendChild(li);
        });
        if (!all.length) {
            var empty = document.createElement('li');
            empty.className = 'cz-empty';
            empty.textContent = t.noRows;
            list.appendChild(empty);
        }

        var count = state.locations[location_].other || 0;
        other.hidden = count === 0;
        other.textContent = count ? other.getAttribute('data-text-' + (count === 1 ? 'one' : 'many')).replace('%d', count) : '';

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

    function select(ref, focusPanel) {
        selected = ref;
        renderList();
        renderPanel();
        post({select: ref});
        if (focusPanel) {
            var first = sectionFields.querySelector('input:not([type="hidden"]), select, textarea, button');
            if (first) {
                first.focus();
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
            select(ref, false);
        } else if (act === 'up' || act === 'down') {
            move(ref, act === 'up' ? -1 : 1, act);
        } else if (act === 'eye') {
            current()[i].enabled = !current()[i].enabled;
            renderList({ref: ref, act: 'eye'});
            structural();
        } else if (act === 'remove') {
            var all = current();
            all.splice(i, 1);
            if (selected === ref) {
                selected = all.length ? all[Math.min(i, all.length - 1)].ref : null;
                renderPanel();
            }
            renderList(selected ? {ref: selected, act: 'remove'} : null);
            if (!selected) {
                addBtn.focus();
            }
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

    /* Adding a section */

    function closeTypes() {
        typesBox.hidden = true;
        addBtn.setAttribute('aria-expanded', 'false');
    }

    addBtn.addEventListener('click', function () {
        var open = typesBox.hidden;
        typesBox.hidden = !open;
        addBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            var allowed = state.locations[location_].types;
            typesBox.querySelectorAll('[data-type]').forEach(function (li) {
                li.hidden = allowed.indexOf(li.getAttribute('data-type')) === -1;
            });
            var first = typesBox.querySelector('li:not([hidden]) button');
            if (first) {
                first.focus();
            }
        }
    });

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
            config: fieldset ? readConfig(fieldset) : {}
        });
        closeTypes();
        select(key, true);
        structural();
    });

    typesBox.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeTypes();
            addBtn.focus();
        }
    });

    /* The selected section's fields */

    function fieldsetFor(type) {
        var fs = template.content.querySelector('[data-type-id="' + CSS.escape(type) + '"]');
        return fs ? fs.cloneNode(true) : null;
    }

    function configName(el) {
        var m = /^config\[(.+)\]$/.exec(el.name || '');
        return m ? m[1] : null;
    }

    function isText(el) {
        return el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type === 'text');
    }

    function readConfig(scope) {
        var config = {};
        scope.querySelectorAll('[name^="config["]').forEach(function (el) {
            var name = configName(el);
            if (name !== null) {
                config[name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
            }
        });
        return config;
    }

    function renderPanel() {
        sectionFields.textContent = '';
        var i = find(selected);
        if (i === -1) {
            sectionTitle.textContent = '';
            var hint = document.createElement('p');
            hint.className = 'cz-hint';
            hint.textContent = current().length ? t.pickRow : t.noRows;
            sectionFields.appendChild(hint);
            return;
        }
        var row = current()[i];
        sectionTitle.textContent = labelOf(row);
        var fieldset = fieldsetFor(row.type);
        if (!fieldset) {
            var none = document.createElement('p');
            none.className = 'cz-hint';
            none.textContent = t.noFields;
            sectionFields.appendChild(none);
            return;
        }
        fieldset.hidden = false;
        fieldset.querySelectorAll('[name^="config["]').forEach(function (el) {
            var name = configName(el);
            el.disabled = false;
            if (name === null || !Object.prototype.hasOwnProperty.call(row.config, name)) {
                return;
            }
            var value = row.config[name];
            if (el.type === 'checkbox') {
                el.checked = !!value && value !== '0';
            } else {
                el.value = value === null ? '' : String(value);
            }
        });
        sectionFields.appendChild(fieldset);
        fieldset.querySelectorAll('.widget-image-input').forEach(function (input) {
            if (window.oscSyncWidgetImage) {
                window.oscSyncWidgetImage(input);
            }
        });
    }

    function onSectionField(e, typing) {
        var el = e.target;
        var name = configName(el);
        var i = find(selected);
        if (name === null || i === -1) {
            return;
        }
        var row = current()[i];
        var value = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
        if (String(row.config[name]) === String(value)) {
            return;
        }
        row.config[name] = value;
        if (isText(el)) {
            if (typing) {
                post({text: {section: row.ref, field: name, value: el.value}});
            }
            changed(false, location_);
        } else if (!typing) {
            changed(true, location_);
        }
    }

    sectionFields.addEventListener('input', function (e) {
        onSectionField(e, true);
    });
    sectionFields.addEventListener('change', function (e) {
        onSectionField(e, false);
    });

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
            .catch(function (err) {
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
                renderPanel();
            }
        });
    }

    var publishBtn = document.getElementById('cz-publish');
    publishBtn.addEventListener('click', function () {
        publishBtn.disabled = true;
        flush().then(function (ok) {
            if (ok === false) {
                publishBtn.disabled = false;
                return null;
            }
            setStatus(t.publishing);
            return postAction(state.urls.publish).then(function (r) {
                if (r.status === 200 && r.json.error === 0) {
                    setStatus(t.published);
                    message('ok', t.publishedOk);
                    return refresh().then(reloadFrame);
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
            publishBtn.disabled = false;
        });
    });

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

    pageSelect.addEventListener('change', function () {
        location_ = pageSelect.value;
        selected = current().length ? current()[0].ref : null;
        closeTypes();
        renderList();
        renderPanel();
        reloadFrame();
    });

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
    location_ = pageSelect.value || location_;
    renderList();
    renderPanel();
    // The frame may have loaded before this script ran, so its ready message was missed.
    frame.addEventListener('load', function () {
        post({select: selected});
    });
    post({select: selected});
    setStatus(state.updated_at > 0 ? t.kept : t.clean);
})();
