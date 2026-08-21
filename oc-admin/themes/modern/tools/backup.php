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

/**
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Backup'),
    'help'    => __("Save a backup of all of your site's information: listings, users and configuration."
                    . ' You can save a backup on your server or on your computer.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="backup-setting">
        <!-- settings form -->
        <div id="backup-settings">
            <?php osc_admin_page_head(__('Backup')); ?>
            <form id="backup_form" name="backup_form" action="<?php echo osc_admin_base_url(true); ?>" method="post">
                <input type="hidden" name="page" value="tools"/>
                <fieldset>
                    <div class="form-horizontal">
                        <div class="form-row">
                            <div class="form-label"><?php _e('Backup folder'); ?></div>
                            <div class="form-controls">
                                <input type="text" class="input-large" name="bck_dir"
                                       value="<?php echo osc_esc_html(osc_base_path()); ?>"/>
                                <div class="callout-warning">
                                    <?php _e("If you don't specify a backup folder, the backup files will be "
                                             . "created in the root of your Shopclass installation."); ?>
                                </div>
                                <div class="help-box">
                                    <?php _e('This is the folder in which your backups will be created. We recommend that you choose a non-public path.'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                        <div class="form-label"><?php _e('Backup Method');?></div>
                            <div class="form-controls">
                                <select class="form-select form-select-sm" name="action">
                                    <option><?php _e('Choose backup method'); ?></option>
                                    <option value="backup-sql"><?php _e('Backup SQL (store on server)'); ?></option>
                                    <option value="backup-sql_file"><?php _e('Backup SQL (download file)'); ?></option>
                                    <option value="backup-zip"><?php _e('Backup files (store on server)'); ?></option>
                                </select>
                            </div>
                        </div>
                        <?php osc_admin_form_actions(array(
                            array('label' => __('Submit'), 'type' => 'submit', 'variant' => 'primary'),
                        )); ?>
                    </div>
                </fieldset>
            </form>
        </div>
        <!-- /settings form -->
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>