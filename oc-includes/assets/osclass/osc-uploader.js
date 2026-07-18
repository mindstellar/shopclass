/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * osc-uploader — a framework-free image uploader for the Shopclass item form.
 * Replaces the jQuery fine-uploader plugin. Drag-and-drop or click to add, instant
 * local previews, per-file upload progress, remove, and set-primary (the first item
 * is the primary image, mirrored in the ajax_photos[] input order the server reads).
 *
 * Init: oscPhotoUploader(rootEl, config) where config = {
 *   endpoint, deleteEndpoint, tempBase, fieldName='qqfile',
 *   maxImages=0 (0 = unlimited), maxSizeBytes=0, allowedExtensions=[],
 *   showPrimary=false, i18n={...}
 * }
 *
 * Hooks for theme authors: state classes (is-dragover, is-uploading, is-done,
 * is-primary) plus bubbling CustomEvents on the root — osc-upload:added,
 * osc-upload:removed, osc-upload:primary, osc-upload:error.
 */
function oscPhotoUploader(root, cfg) {
    if (!root || root.getAttribute('data-osc-uploader-init')) {
        return;
    }
    root.setAttribute('data-osc-uploader-init', '1');
    cfg = cfg || {};
    var maxImages = cfg.maxImages || 0;
    var maxSize = cfg.maxSizeBytes || 0;
    var exts = (cfg.allowedExtensions || []).map(function (e) { return String(e).toLowerCase(); });
    var i18n = cfg.i18n || {};

    var grid = root.querySelector('.osc-uploader-grid');
    var input = root.querySelector('.osc-uploader-input');
    var drop = root.querySelector('.osc-uploader-drop');
    var errBox = root.querySelector('.osc-uploader-errors');

    function t(key, fallback) { return i18n[key] != null ? i18n[key] : fallback; }
    function fill(msg, map) { return msg.replace(/\{(\w+)\}/g, function (m, k) { return map[k] != null ? map[k] : m; }); }
    function emit(name, detail) { root.dispatchEvent(new CustomEvent('osc-upload:' + name, { bubbles: true, detail: detail || {} })); }
    function items() { return grid.querySelectorAll('.osc-uploader-item'); }
    function extOf(name) { var i = name.lastIndexOf('.'); return i < 0 ? '' : name.slice(i + 1).toLowerCase(); }

    function clearErrors() { errBox.textContent = ''; }
    function showError(msg) {
        var d = document.createElement('div');
        d.className = 'osc-uploader-error';
        d.setAttribute('role', 'alert');
        var span = document.createElement('span');
        span.textContent = msg;
        d.appendChild(span);
        var x = document.createElement('button');
        x.type = 'button';
        x.className = 'osc-uploader-error-close';
        x.setAttribute('aria-label', t('close', 'Close'));
        x.innerHTML = '&times;';
        x.addEventListener('click', function () { d.remove(); });
        d.appendChild(x);
        errBox.appendChild(d);
        emit('error', { message: msg });
    }

    function validate(file) {
        if (exts.length && exts.indexOf(extOf(file.name)) < 0) {
            return fill(t('typeError', '{file} has an invalid extension.'), { file: file.name, extensions: exts.join(', ') });
        }
        if (maxSize && file.size > maxSize) {
            return fill(t('sizeError', '{file} is too large.'), { file: file.name });
        }
        return null;
    }

    function refreshPrimary() {
        if (!cfg.showPrimary) {
            return;
        }
        var all = items();
        for (var i = 0; i < all.length; i++) {
            var it = all[i];
            it.classList.toggle('is-primary', i === 0);
            var badge = it.querySelector('.osc-uploader-badge');
            var mk = it.querySelector('.osc-uploader-primary');
            if (badge) { badge.hidden = i !== 0; }
            if (mk) { mk.hidden = i === 0; }
        }
    }

    function wire(item) {
        var rm = item.querySelector('.osc-uploader-remove');
        if (rm) { rm.addEventListener('click', function () { removeItem(item); }); }
        var mk = item.querySelector('.osc-uploader-primary');
        if (mk) {
            mk.addEventListener('click', function () {
                grid.insertBefore(item, grid.firstChild);
                refreshPrimary();
                emit('primary', { item: item });
            });
        }
    }

    function removeItem(item) {
        if (!window.confirm(t('confirmDelete', 'This action cannot be undone. Are you sure?'))) {
            return;
        }
        var params = null;
        if (item.getAttribute('data-temp')) {
            params = 'ajax_photo=' + encodeURIComponent(item.getAttribute('data-temp'));
        } else if (item.getAttribute('data-id')) {
            params = 'id=' + encodeURIComponent(item.getAttribute('data-id')) +
                '&item=' + encodeURIComponent(item.getAttribute('data-item')) +
                '&code=' + encodeURIComponent(item.getAttribute('data-code')) +
                '&secret=' + encodeURIComponent(item.getAttribute('data-secret'));
        }
        var done = function () { item.remove(); refreshPrimary(); emit('removed', {}); };
        if (params === null) { done(); return; }
        var url = cfg.deleteEndpoint + (cfg.deleteEndpoint.indexOf('?') > -1 ? '&' : '?') + params;
        fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); }).then(done).catch(done);
    }

    // Build an item card. `data` = {thumb, alt, temp?, existing?{id,item,code,secret}}
    function makeItem(data) {
        var item = document.createElement('div');
        item.className = 'osc-uploader-item';

        var img = document.createElement('img');
        img.className = 'osc-uploader-thumb';
        img.src = data.thumb || '';
        img.alt = data.alt || '';
        item.appendChild(img);

        if (data.temp) {
            item.setAttribute('data-temp', data.temp);
            var hid = document.createElement('input');
            hid.type = 'hidden';
            hid.name = 'ajax_photos[]';
            hid.value = data.temp;
            item.appendChild(hid);
        }
        if (data.existing) {
            item.setAttribute('data-id', data.existing.id);
            item.setAttribute('data-item', data.existing.item);
            item.setAttribute('data-code', data.existing.code);
            item.setAttribute('data-secret', data.existing.secret);
        }

        if (cfg.showPrimary) {
            var badge = document.createElement('span');
            badge.className = 'osc-uploader-badge';
            badge.textContent = t('primary', 'Primary');
            badge.hidden = true;
            item.appendChild(badge);

            var mk = document.createElement('button');
            mk.type = 'button';
            mk.className = 'osc-uploader-primary';
            mk.textContent = t('makePrimary', 'Make primary');
            item.appendChild(mk);
        }

        var rm = document.createElement('button');
        rm.type = 'button';
        rm.className = 'osc-uploader-remove';
        rm.setAttribute('aria-label', t('delete', 'Delete'));
        rm.innerHTML = '&times;';
        item.appendChild(rm);

        wire(item);
        return item;
    }

    function upload(file) {
        clearErrors();
        var err = validate(file);
        if (err) { showError(err); return; }
        if (maxImages && items().length >= maxImages) {
            showError(fill(t('tooMany', 'Too many images. The limit is {limit}.'), { limit: maxImages }));
            return;
        }

        var objURL = URL.createObjectURL(file);
        var item = makeItem({ thumb: objURL, alt: file.name });
        item.classList.add('is-uploading');
        var prog = document.createElement('div');
        prog.className = 'osc-uploader-progress';
        var bar = document.createElement('div');
        bar.className = 'osc-uploader-progress-bar';
        prog.appendChild(bar);
        item.appendChild(prog);
        grid.appendChild(item);
        refreshPrimary();

        var fd = new FormData();
        fd.append(cfg.fieldName || 'qqfile', file, file.name);

        // XHR (not fetch) for upload progress events.
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.endpoint, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) { bar.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
        };
        xhr.onload = function () {
            URL.revokeObjectURL(objURL);
            var data = null;
            try { data = JSON.parse(xhr.responseText); } catch (e) { data = null; }
            if (data && data.success && data.uploadName) {
                item.classList.remove('is-uploading');
                item.classList.add('is-done');
                prog.remove();
                item.setAttribute('data-temp', data.uploadName);
                var hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'ajax_photos[]';
                hid.value = data.uploadName;
                item.appendChild(hid);
                if (cfg.tempBase) { item.querySelector('.osc-uploader-thumb').src = cfg.tempBase + data.uploadName; }
                emit('added', { name: data.uploadName });
            } else {
                item.remove();
                refreshPrimary();
                showError(fill(t('failUpload', '{file} could not be uploaded.'), { file: file.name }));
            }
        };
        xhr.onerror = function () {
            URL.revokeObjectURL(objURL);
            item.remove();
            refreshPrimary();
            showError(fill(t('failUpload', '{file} could not be uploaded.'), { file: file.name }));
        };
        xhr.send(fd);
    }

    function handleFiles(files) {
        Array.prototype.forEach.call(files, upload);
    }

    if (input) {
        input.addEventListener('change', function () {
            handleFiles(input.files);
            input.value = '';
        });
    }
    if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-dragover'); });
        });
        ['dragleave', 'dragend'].forEach(function (ev) {
            drop.addEventListener(ev, function () { drop.classList.remove('is-dragover'); });
        });
        drop.addEventListener('drop', function (e) {
            e.preventDefault();
            drop.classList.remove('is-dragover');
            if (e.dataTransfer && e.dataTransfer.files) { handleFiles(e.dataTransfer.files); }
        });
    }

    // Wire the server-rendered items (existing + session temp images) and set primary.
    Array.prototype.forEach.call(items(), wire);
    refreshPrimary();
}
