<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

osc_admin_page(array(
    'section' => __('Plugins'),
    'title'   => __('Add plugin'),
    'help'    => __('Manually upload Shopclass plugins in .zip format. If you prefer, '
                    . 'you can manually upload the decompressed plugin to <em>oc-content/plugins</em>.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div class="appearance">
    <?php osc_admin_page_head(__('Add plugin')); ?>
    <div id="upload-plugins">
        <div class="form-horizontal">
            <?php if (is_writable(osc_plugins_path())) { ?>
                <form class="separate-top" action="<?php echo osc_admin_base_url(true); ?>" method="post"
                      enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_post"/>
                    <input type="hidden" name="page" value="plugins"/>

                    <div class="form-row">
                        <div class="form-label"><?php _e('Plugin package (.zip)'); ?></div>
                        <div class="form-controls">
                            <div class="form-label-checkbox"><input type="file" name="package" id="package"/></div>
                        </div>
                    </div>
                    <?php osc_admin_form_actions(array(
                        array('label' => __('Upload'), 'type' => 'submit'),
                    )); ?>
                </form>
            <?php } else { ?>
                <div class="flashmessage flashmessage-error">
                    <a class="btn ico btn-mini ico-close" href="#">×</a>
                    <p><?php _e('Cannot install new plugin'); ?></p>
                </div>
                <p class="text">
                    <?php _e('The plugin folder is not writable on your server so you cannot upload '
                             . 'plugins from the administration panel. Please make the folder writable and try again.');
                ?>
                </p>
                <p class="text">
                    <?php _e('To make the directory writable under UNIX execute this command from the shell:'); ?>
                </p>
                <pre>chmod 0755 <?php echo osc_plugins_path(); ?></pre>
            <?php } ?>
        </div>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
