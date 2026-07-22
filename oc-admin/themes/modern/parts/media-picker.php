<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Reusable media picker. Include once on an admin page, then call
 * window.oscMediaPicker.open(function (url) { ... }) to let the user browse the
 * unified media library or upload a new image; the callback receives the chosen
 * image URL. Uploads made here are unattached ("library") resources.
 */
$mpListUrl   = osc_admin_base_url(true) . '?page=ajax&action=media_list';
$mpUploadUrl = osc_admin_base_url(true) . '?page=ajax&action=resource_upload&owner_type=library&owner_id=0&'
    . osc_csrf_token_url();
?>
<dialog id="oscMediaPicker" class="osc-dialog osc-dialog-wide media-picker">
    <div class="osc-dialog-body">
        <div class="media-picker-head">
            <p class="osc-dialog-title"><?php _e('Media library'); ?></p>
            <label class="btn btn-secondary btn-sm media-picker-upload">
                <i class="bi bi-upload" aria-hidden="true"></i> <?php _e('Upload'); ?>
                <input type="file" accept="image/*" hidden/>
            </label>
        </div>
        <div class="media-picker-status media-picker-loading" hidden><?php _e('Loading…'); ?></div>
        <div class="media-picker-status media-picker-empty" hidden>
            <?php _e('No media here yet. Upload an image to get started.'); ?>
        </div>
        <div class="media-picker-grid" role="listbox" aria-label="<?php echo osc_esc_html(__('Media')); ?>"></div>
    </div>
    <div class="osc-dialog-actions">
        <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
    </div>
</dialog>
<script>
    window.oscMediaPicker = (function () {
        'use strict';
        var listUrl   = <?php echo json_encode($mpListUrl); ?>;
        var uploadUrl = <?php echo json_encode($mpUploadUrl); ?>;
        var dialog, grid, emptyBox, loadingBox, fileInput, callback = null;

        function init() {
            dialog = document.getElementById('oscMediaPicker');
            if (!dialog) { return false; }
            grid       = dialog.querySelector('.media-picker-grid');
            emptyBox   = dialog.querySelector('.media-picker-empty');
            loadingBox = dialog.querySelector('.media-picker-loading');
            fileInput  = dialog.querySelector('.media-picker-upload input[type=file]');
            fileInput.addEventListener('change', onUpload);
            grid.addEventListener('click', onPick);
            return true;
        }

        function setBusy(on) { if (loadingBox) { loadingBox.hidden = !on; } }

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
            fetch(listUrl, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (j) { setBusy(false); render(j && j.items); })
                .catch(function () { setBusy(false); });
        }

        function onPick(e) {
            var item = e.target.closest ? e.target.closest('.media-picker-item') : null;
            if (!item) { return; }
            select(item.getAttribute('data-url'));
        }

        function onUpload() {
            var file = fileInput.files && fileInput.files[0];
            if (!file) { return; }
            var data = new FormData();
            data.append('file', file);
            setBusy(true);
            fetch(uploadUrl, { method: 'POST', credentials: 'same-origin', body: data })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    fileInput.value = '';
                    setBusy(false);
                    // Upload then insert: a just-uploaded image is what you wanted.
                    if (j && j.location) { select(j.location); }
                })
                .catch(function () { setBusy(false); });
        }

        function select(url) {
            if (url && typeof callback === 'function') { callback(url); }
            close();
        }

        function open(cb) {
            if (!dialog && !init()) { return; }
            callback = (typeof cb === 'function') ? cb : null;
            load();
            dialog.showModal();
        }

        function close() { if (dialog && typeof dialog.close === 'function') { dialog.close(); } }

        return { open: open };
    })();
</script>
