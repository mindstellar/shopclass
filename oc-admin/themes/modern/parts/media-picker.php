<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Reusable media picker. Include once on an admin page, then call
 * window.oscMediaPicker.open(function (url) { ... }); js/media-picker.js drives it.
 * Uploads made here are unattached ("library") resources.
 */
// Site images only: library, page and settings uploads, never a listing or user photo.
$mpListUrl   = osc_admin_base_url(true) . '?page=ajax&action=media_list&type=:site';
$mpUploadUrl = adminLibraryUploadUrl();
?>
<dialog id="oscMediaPicker" class="osc-dialog osc-dialog-wide media-picker" aria-labelledby="oscMediaPickerTitle"
        data-list-url="<?php echo osc_esc_html($mpListUrl); ?>"
        data-upload-url="<?php echo osc_esc_html($mpUploadUrl); ?>"
        data-msg-upload="<?php echo osc_esc_html(__('The upload failed. Try again.')); ?>"
        data-msg-not-image="<?php echo osc_esc_html(__('The file is not a valid image')); ?>">
    <div class="osc-dialog-body">
        <p class="osc-dialog-title media-picker-title" id="oscMediaPickerTitle">
            <i class="bi bi-image" aria-hidden="true"></i><?php _e('Add an image'); ?>
            <button type="button" class="media-picker-close" data-osc-dialog-close aria-label="<?php echo osc_esc_html(__('Close')); ?>">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
        </p>
        <div class="media-picker-sources">
            <div class="media-picker-drop">
                <span class="media-picker-drop-icon" aria-hidden="true"><i class="bi bi-cloud-arrow-up"></i></span>
                <p class="media-picker-drop-text">
                    <strong class="media-picker-drop-idle"><?php _e('Drop an image here'); ?></strong>
                    <strong class="media-picker-drop-busy" role="status"><?php _e('Uploading…'); ?></strong>
                    <span><?php _e('JPG, PNG, GIF or WebP'); ?></span>
                </p>
                <button type="button" class="btn btn-secondary btn-sm media-picker-upload" autofocus>
                    <i class="bi bi-upload" aria-hidden="true"></i> <?php _e('Choose a file'); ?>
                </button>
                <input type="file" class="media-picker-file" accept="image/*" hidden/>
            </div>
            <div class="media-picker-link">
                <label for="oscMediaPickerLink" class="media-picker-label"><?php _e('Or link to an image'); ?></label>
                <div class="media-picker-link-row">
                    <input type="url" id="oscMediaPickerLink" class="form-control media-picker-link-input"
                           inputmode="url" autocomplete="off" spellcheck="false"
                           placeholder="<?php echo osc_esc_html(__('https://… or /path')); ?>"
                           aria-describedby="oscMediaPickerLinkHelp oscMediaPickerLinkError"/>
                    <button type="button" class="btn btn-secondary media-picker-link-add"><?php _e('Add'); ?></button>
                </div>
                <p class="media-picker-hint" id="oscMediaPickerLinkHelp"><?php _e('An image on another site, or a path on this one.'); ?></p>
                <p class="field-error media-picker-link-error" id="oscMediaPickerLinkError" hidden>
                    <?php _e('A link needs an address that starts with http://, https:// or /.'); ?>
                </p>
            </div>
        </div>
        <p class="field-error media-picker-error" role="alert" hidden></p>
        <p class="media-picker-label" id="oscMediaPickerLibrary"><?php _e('Or pick from your library'); ?></p>
        <div class="media-picker-status media-picker-loading" hidden><?php _e('Loading…'); ?></div>
        <div class="media-picker-status media-picker-empty" hidden>
            <i class="bi bi-images" aria-hidden="true"></i>
            <?php _e('No images yet. Upload one or add a link above.'); ?>
        </div>
        <div class="media-picker-grid" role="group" aria-labelledby="oscMediaPickerLibrary"></div>
    </div>
</dialog>
<script src="<?php echo osc_esc_html(osc_asset_url_versioned(osc_current_admin_theme_js_url('media-picker.js'))); ?>"></script>
