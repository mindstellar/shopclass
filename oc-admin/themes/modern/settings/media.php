<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The chrome around the declared media form. The form itself -- its fields, the two
 * watermark blocks and the submit row -- is core's; the regenerate button and the
 * keep-original recommendation stay here.
 */

$form = __get('media_form');

// Watermarks are stamped onto the stored image, so recommend keeping the original whenever
// one is chosen with the original switched off.
$media_js = static function () {
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            var form = document.querySelector('form[name=media_form]');
            var keep = form && form.querySelector('input[name="keep_original_image"]');
            if (!keep) {
                return;
            }
            function warn() {
                var none = form.querySelector('#watermark_none');
                if (!keep.checked && none && !none.checked) {
                    document.getElementById('dialog-watermark-warning').showModal();
                }
            }
            ['#watermark_text', '#watermark_image'].forEach(function (id) {
                var radio = form.querySelector(id);
                if (radio) {
                    radio.addEventListener('change', warn);
                }
            });
            keep.addEventListener('change', warn);
        });
    </script>
    <?php
};

osc_add_hook('admin_footer', $media_js, 10);

osc_admin_page(array(
    'section' => __('Media'),
    'title'   => __('Media Settings'),
    'help'    => __('Manage the options for the images users can upload along with their listings. You can limit their size, '
                    . 'the number of images per ad, include a watermark, etc.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="general-settings">
        <?php osc_admin_page_head(__('Media Settings')); ?>
        <?php osc_admin_settings_form($form['id'], $form); ?>

        <?php osc_admin_action_section(array(
    'title'   => __('Regenerate images'),
    'intro'   => __('You can regenerate different image dimensions. If you have changed the dimension of thumbnails, '
                    . 'preview or normal images, you might want to regenerate your images.'),
    'actions' => array(
        array(
            'label'   => __('Regenerate'),
            'variant' => 'dim',
            'url'     => osc_admin_base_url(true) . '?page=settings&action=images_post&' . osc_csrf_token_url(),
        ),
    ),
)); ?>
    </div>
    <dialog id="dialog-watermark-warning" class="osc-dialog">
        <div class="osc-dialog-body">
            <p class="osc-dialog-title"><?php echo osc_esc_html(__('Recommendation')); ?></p>
            <p class="osc-dialog-text"><?php _e("We highly recommend you have the 'Keep original image' option active when you use watermarks."); ?></p>
        </div>
        <div class="osc-dialog-actions">
            <button type="button" class="btn btn-dim btn-sm" data-osc-dialog-close><?php _e('Cancel'); ?></button>
        </div>
    </dialog>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>