<?php if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Osclass (Mindstellar).
 * Copyright (c) 2014 Osclass (original work, licensed under the Apache License 2.0)
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. The original
 * Osclass code it derives from was licensed under the Apache License 2.0.
 * See LICENSE (GPL-3.0) and LICENSE-APACHE (Apache-2.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

function addHelp()
{
    echo '<p>'
         . __('Manually upload Osclass plugins in .zip format. If you prefer, '
              . 'you can manually upload the decompressed plugin to <em>oc-content/plugins</em>.')
         . '</p>';
}


osc_add_hook('help_box', 'addHelp');

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Plugins'); ?>
        <a class="ms-1 bi bi-question-circle-fill float-right" data-bs-target="#help-box" data-bs-toggle="collapse"
           href="#help-box"></a>
    </h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Add plugin &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div class="appearance">
    <h2 class="render-title"><?php _e('Add plugin'); ?></h2>
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
                    <div class="form-actions">
                        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Upload')); ?></button>
                    </div>
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
