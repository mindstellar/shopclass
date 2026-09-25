/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
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
 *   showPrimary=false, i18n={...},
 *   resize=null | {maxWidth, maxHeight, minBytes, quality, onlyOversize}
 * }
 *
 * With `resize`, a JPEG, PNG or WebP larger than the box is shrunk in the browser before
 * upload, keeping its format. Any failure sends the original. A theme turns it off with
 * data-osc-resize="off" on the root.
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
        return null;
    }

    function tooLarge(file) {
        return maxSize && file.size > maxSize ? fill(t('sizeError', '{file} is too large.'), { file: file.name }) : null;
    }

    var resize = root.getAttribute('data-osc-resize') === 'off' ? null : cfg.resize;
    var RESIZABLE = ['image/jpeg', 'image/png', 'image/webp'];
    var queue = Promise.resolve();

    function wantsResize(file) {
        if (!resize || RESIZABLE.indexOf(file.type) < 0 || typeof HTMLCanvasElement.prototype.toBlob !== 'function') {
            return false;
        }
        return !resize.onlyOversize || (maxSize && file.size > maxSize);
    }

    // The size the server would scale to, or null when the image already fits. Rounded as
    // the server rounds, so the server does not scale it again.
    function fitBox(w, h) {
        var bw = resize.maxWidth, bh = resize.maxHeight;
        if (w <= bw && h <= bh) {
            return null;
        }
        return w / h >= bw / bh ? [bw, Math.ceil(h * bw / w)] : [Math.ceil(w * bh / h), bh];
    }

    // Decoded upright: the camera's rotation applied, so nothing needs the metadata after.
    function decode(file) {
        var viaImg = function () {
            return new Promise(function (ok, fail) {
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function () { URL.revokeObjectURL(url); ok(img); };
                img.onerror = function () { URL.revokeObjectURL(url); fail(new Error('decode')); };
                img.src = url;
            });
        };
        if (typeof createImageBitmap !== 'function') {
            return viaImg();
        }
        return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(viaImg);
    }

    function canvasOf(w, h) {
        var c = document.createElement('canvas');
        c.width = w;
        c.height = h;
        return c;
    }

    // Halving first keeps a large reduction from aliasing in browsers that draw in one pass.
    function draw(src, w, h, tw, th) {
        var cur = src;
        while (w / 2 >= tw && h / 2 >= th) {
            w = Math.round(w / 2);
            h = Math.round(h / 2);
            var step = canvasOf(w, h);
            var sctx = step.getContext('2d');
            sctx.imageSmoothingQuality = 'high';
            sctx.drawImage(cur, 0, 0, w, h);
            cur = step;
        }
        var out = canvasOf(tw, th);
        var ctx = out.getContext('2d');
        ctx.imageSmoothingQuality = 'high';
        ctx.drawImage(cur, 0, 0, tw, th);
        return out;
    }

    // Resolves with the file to send: the original whenever shrinking fails or does not help.
    function shrink(file) {
        if (!wantsResize(file)) {
            return Promise.resolve(file);
        }
        return decode(file).then(function (src) {
            var w = src.naturalWidth || src.width;
            var h = src.naturalHeight || src.height;
            var box = fitBox(w, h);
            if (!box && file.size <= resize.minBytes && !tooLarge(file)) {
                if (src.close) { src.close(); }
                return file;
            }
            var canvas = draw(src, w, h, box ? box[0] : w, box ? box[1] : h);
            if (src.close) { src.close(); }
            return new Promise(function (ok) {
                canvas.toBlob(ok, file.type, resize.quality);
            }).then(function (blob) {
                // A browser that cannot write the format hands back a PNG instead.
                if (!blob || blob.type !== file.type || blob.size >= file.size) {
                    return file;
                }
                return new File([blob], file.name, { type: file.type, lastModified: file.lastModified });
            });
        }).catch(function () {
            return file;
        });
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
        var err = validate(file) || (wantsResize(file) ? null : tooLarge(file));
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

        item.classList.add('is-resizing');
        // One photo at a time: a dozen decoded phone photos at once can exhaust a phone's memory.
        queue = queue.then(function () { return shrink(file); }).then(function (out) {
            item.classList.remove('is-resizing');
            if (!item.isConnected) {
                URL.revokeObjectURL(objURL);
                return;
            }
            var big = tooLarge(out);
            if (big) {
                URL.revokeObjectURL(objURL);
                item.remove();
                refreshPrimary();
                showError(big);
                return;
            }
            send(out, item, prog, bar, objURL);
        });
    }

    function send(file, item, prog, bar, objURL) {
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
