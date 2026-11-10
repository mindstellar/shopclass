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

osc_admin_page(array(
    'section' => __('Appearance'),
    'heading' => __('Add new theme'),
    'title'   => __('Add theme'),
    'help'    => __('Manually add Shopclass themes in .zip format. If you prefer, '
                    . 'you can manually upload the decompressed theme to <em>oc-content/themes</em>.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_body_open(array('class' => 'appearance')); ?>
    <div id="upload-themes" class="ui-osc-tabs-panel">
        <div class="form-horizontal">
            <?php if (is_writable(osc_themes_path())) { ?>
                <form action="<?php echo osc_admin_base_url(true); ?>" method="post"
                      enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_post"/>
                    <input type="hidden" name="page" value="appearance"/>
                    <?php osc_admin_field(array(
                        'type'  => 'file',
                        'id'    => 'package',
                        'name'  => 'package',
                        'label' => __('Theme package (.zip)'),
                        'attrs' => array('accept' => '.zip'),
                    )); ?>
                    <?php osc_admin_form_actions(array(
                        array('label' => __('Upload'), 'type' => 'submit'),
                        array(
                            'label'   => __('Back to themes'),
                            'type'    => 'link',
                            'url'     => osc_admin_base_url(true) . '?page=appearance',
                            'variant' => 'secondary',
                        ),
                    )); ?>
                </form>
            <?php } else { ?>
                <?php osc_admin_callout('danger', __("Can't install a new theme")); ?>
                <p class="text">
                    <?php _e("The theme folder is not writable on your server so you can't upload themes from "
                             . "the administration panel. Please make the theme folder writable and try again."); ?>
                </p>
                <p class="text">
                    <?php _e('To make the directory writable under UNIX execute this command from the shell:'); ?>
                </p>
                <pre>chmod 0755 <?php echo osc_themes_path(); ?></pre>
            <?php } ?>
        </div>
    </div>
<?php osc_admin_body_close(); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
