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
 * Filter callback for `render-wrapper`: the CSS class the page wrapper renders with.
 *
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
                        <?php
                        osc_admin_text(array(
                            'name'      => 'bck_dir',
                            'label'     => __('Backup folder'),
                            'value'     => osc_base_path(),
                            'width'     => 'key',
                            'help_html' => '<span class="callout-warning">' . osc_esc_html(__(
                                "If you don't specify a backup folder, the backup files will be created in the "
                                . 'root of your Shopclass installation.'
                            )) . '</span> ' . osc_esc_html(__(
                                'This is the folder in which your backups will be created. We recommend that you '
                                . 'choose a non-public path.'
                            )),
                        ));
                        osc_admin_select(array(
                            'name'        => 'action',
                            'label'       => __('Backup Method'),
                            'placeholder' => __('Choose backup method'),
                            'width'       => 'text',
                            'options'     => array(
                                'backup-sql'      => __('Backup SQL (store on server)'),
                                'backup-sql_file' => __('Backup SQL (download file)'),
                                'backup-zip'      => __('Backup files (store on server)'),
                            ),
                        )); ?>
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