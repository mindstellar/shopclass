/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The media picker (parts/media-picker.php): upload or drop a file, pick from the library,
 * or add a link. window.oscMediaPicker.open(cb) calls cb(url) with the chosen image.
 * Also drives the widget "image" fields, which choose through the same picker.
 */
window.oscMediaPicker = (function () {
    'use strict';
    var dialog, grid, emptyBox, loadingBox, errorBox, fileInput, linkInput, linkError, callback = null;

    // What an image link may be: an http(s) address or a site path, as WidgetConfig::imageValue() keeps.
    function linkValue(value) {
        var v = String(value || '').trim();
        var bad = Array.prototype.some.call(v, function (c) { return c.charCodeAt(0) <= 32 || c.charCodeAt(0) === 127; });
        if (v === '' || bad || /["<>\\]/.test(v)) { return ''; }
        if (/^\/(?![/\\])/.test(v)) { return v; }
        if (!/^https?:\/\/[^/?#]/i.test(v)) { return ''; }
        try {
            var u = new URL(v);
            return (u.protocol === 'http:' || u.protocol === 'https:') && u.hostname ? v : '';
        } catch {
            return '';
        }
    }

    function init() {
        dialog = document.getElementById('oscMediaPicker');
        if (!dialog) { return false; }
        grid       = dialog.querySelector('.media-picker-grid');
        emptyBox   = dialog.querySelector('.media-picker-empty');
        loadingBox = dialog.querySelector('.media-picker-loading');
        errorBox   = dialog.querySelector('.media-picker-error');
        fileInput  = dialog.querySelector('.media-picker-file');
        linkInput  = dialog.querySelector('.media-picker-link-input');
        linkError  = dialog.querySelector('.media-picker-link-error');

        fileInput.addEventListener('change', function () {
            upload(fileInput.files && fileInput.files[0]);
        });
        dialog.querySelector('.media-picker-drop').addEventListener('click', function () {
            if (!dialog.classList.contains('is-uploading')) { fileInput.click(); }
        });
        dialog.querySelector('.media-picker-link-add').addEventListener('click', addLink);
        linkInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addLink(); }
        });
        linkInput.addEventListener('input', function () { showLinkError(false); });
        grid.addEventListener('click', onPick);

        dialog.addEventListener('dragover', function (e) {
            if (!hasFiles(e)) { return; }
            e.preventDefault();
            dialog.classList.add('is-dragging');
        });
        dialog.addEventListener('dragleave', function (e) {
            if (!e.relatedTarget || !dialog.contains(e.relatedTarget)) { dialog.classList.remove('is-dragging'); }
        });
        dialog.addEventListener('drop', function (e) {
            if (!hasFiles(e)) { return; }
            e.preventDefault();
            dialog.classList.remove('is-dragging');
            var files = Array.prototype.slice.call(e.dataTransfer.files || []);
            var file = files.find(function (f) { return /^image\//.test(f.type); });
            if (file) { upload(file); } else { showError(dialog.getAttribute('data-msg-not-image')); }
        });
        return true;
    }

    function hasFiles(e) {
        return !!(e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') !== -1);
    }

    function setBusy(on) { if (loadingBox) { loadingBox.hidden = !on; } }

    function setUploading(on) {
        dialog.classList.toggle('is-uploading', on);
        dialog.querySelector('.media-picker-upload').disabled = on;
    }

    function showError(msg) {
        if (!errorBox) { return; }
        errorBox.textContent = msg || '';
        errorBox.hidden = !msg;
    }

    function showLinkError(on) {
        linkError.hidden = !on;
        if (on) { linkInput.setAttribute('aria-invalid', 'true'); } else { linkInput.removeAttribute('aria-invalid'); }
    }

    function render(items) {
        grid.innerHTML = '';
        if (!items || !items.length) { emptyBox.hidden = false; return; }
        emptyBox.hidden = true;
        items.forEach(function (it) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'media-picker-item';
            btn.setAttribute('data-url', it.url);
            btn.title = it.name || '';
            var img = document.createElement('img');
            img.src = it.thumb;
            img.loading = 'lazy';
            img.alt = it.name || '';
            btn.appendChild(img);
            grid.appendChild(btn);
        });
    }

    function load() {
        grid.innerHTML = '';
        emptyBox.hidden = true;
        setBusy(true);
        fetch(dialog.getAttribute('data-list-url'), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (j) { setBusy(false); render(j && j.items); })
            .catch(function () { setBusy(false); });
    }

    function onPick(e) {
        var item = e.target.closest ? e.target.closest('.media-picker-item') : null;
        if (item) { select(item.getAttribute('data-url')); }
    }

    function addLink() {
        var url = linkValue(linkInput.value);
        if (!url) {
            showLinkError(true);
            linkInput.focus();
            return;
        }
        select(url);
    }

    function upload(file) {
        if (!file) { return; }
        var data = new FormData();
        data.append('file', file);
        showError('');
        setUploading(true);
        fetch(dialog.getAttribute('data-upload-url'), { method: 'POST', credentials: 'same-origin', body: data })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                fileInput.value = '';
                setUploading(false);
                // A just-uploaded image is the one wanted, so it is chosen at once.
                if (j && j.location) { select(j.location); } else { showError((j && j.error) || dialog.getAttribute('data-msg-upload')); }
            })
            .catch(function () {
                fileInput.value = '';
                setUploading(false);
                showError(dialog.getAttribute('data-msg-upload'));
            });
    }

    function select(url) {
        var cb = callback;
        close();
        if (url && typeof cb === 'function') { cb(url, { alt: '' }); }
    }

    function open(cb) {
        if (!dialog && !init()) { return; }
        callback = (typeof cb === 'function') ? cb : null;
        linkInput.value = '';
        showLinkError(false);
        showError('');
        load();
        dialog.showModal();
    }

    function close() {
        callback = null;
        if (dialog && dialog.open && typeof dialog.close === 'function') { dialog.close(); }
    }

    return { open: open, linkValue: linkValue };
})();

// Widget "image" config fields: a hidden input + preview, chosen via the media picker.
// Delegated so it works for fields shown later (e.g. a block opened in Customise).
(function () {
    'use strict';
    function sync(field) {
        if (!field) { return; }
        var input   = field.querySelector('.widget-image-input');
        var preview = field.querySelector('.widget-image-preview');
        var img     = preview ? preview.querySelector('img') : null;
        var clear   = field.querySelector('.widget-image-clear');
        var url     = input ? input.value : '';
        var meta    = field.querySelector('.widget-image-meta');
        if (img) { img.src = url; }
        if (preview) { preview.hidden = !url; }
        if (clear) { clear.hidden = !url; }
        if (meta) {
            // The file's name, and its size once the browser has it: "stalls.jpg · 1600 × 1067".
            var name = url ? (url.split('?')[0].split('/').pop() || '') : '';
            try { name = decodeURIComponent(name); } catch { /* keep the name as written */ }
            meta.textContent = name;
            meta.hidden = !name;
            if (img && name) {
                var show = function () {
                    if (img.naturalWidth) { meta.textContent = name + ' · ' + img.naturalWidth + ' × ' + img.naturalHeight; }
                };
                if (img.complete) { show(); } else { img.addEventListener('load', show, { once: true }); }
            }
        }
    }
    // Fields drawn with a value already chosen show its name and size too.
    document.querySelectorAll('.widget-image-field').forEach(sync);

    // Called after a script sets a hidden value, such as the Customise field helper.
    window.oscSyncWidgetImage = function (input) {
        sync(input && input.closest ? input.closest('.widget-image-field') : null);
    };

    document.addEventListener('click', function (e) {
        var choose = e.target.closest ? e.target.closest('.widget-image-choose') : null;
        if (choose) {
            e.preventDefault();
            var field = choose.closest('.widget-image-field');
            var input = field && field.querySelector('.widget-image-input');
            if (input && window.oscMediaPicker) {
                window.oscMediaPicker.open(function (url) {
                    try {
                        var u = new URL(url, window.location.href);
                        if (u.origin === window.location.origin && u.pathname.indexOf('//') === -1) { url = u.pathname + u.search; }
                    } catch { /* keep the full address */ }
                    input.value = url;
                    sync(field);
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            }
            return;
        }
        var clear = e.target.closest ? e.target.closest('.widget-image-clear') : null;
        if (clear) {
            e.preventDefault();
            var f = clear.closest('.widget-image-field');
            var inp = f && f.querySelector('.widget-image-input');
            if (inp) {
                inp.value = '';
                sync(f);
                inp.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    });
})();
