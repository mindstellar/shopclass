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

osc_add_hook('admin_page_header', 'customPageHeader');
function customPageHeader()
{
    ?>
    <h1><?php _e('Settings'); ?></h1>
    <?php
}


/**
 * @param $string
 *
 * @return string
 */
function customPageTitle($string)
{
    return sprintf(__('Add language &raquo; %s'), $string);
}


osc_add_filter('admin_title', 'customPageTitle');

osc_current_admin_theme_path('parts/header.php'); ?>
<div class="appearance">
    <h2 class="render-title"><?php _e('Add language'); ?></h2>
    <div id="upload-language">
        <div class="form-horizontal">
            <?php if (is_writable(osc_translations_path())) { ?>
                <form class="separate-top" action="<?php echo osc_admin_base_url(true); ?>" method="post"
                      enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_post"/>
                    <input type="hidden" name="page" value="languages"/>

                    <div class="form-row">
                        <div class="form-label"> <?php _e('Language package (.zip)'); ?></div>
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
                    <p><?php _e("Can't install a new language"); ?></p>
                </div>
                <p class="text">
                    <?php _e('The translations folder is not writable on your server '
                             . "so you can't upload translations from the administration panel. "
                             . 'Please make the translation folder writable and try again.'); ?>
                </p>
                <p class="text">
                    <?php _e('To make the directory writable under UNIX execute this command from the shell:'); ?>
                </p>
                <pre>chmod 0755 <?php echo osc_translations_path(); ?></pre>
            <?php } ?>
        </div>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
