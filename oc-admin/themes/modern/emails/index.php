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
    'section' => __('Settings'),
    'title'   => __('Email templates'),
    'help'    => __("Modify the emails your site's users receive when they join your site,"
                    . " when someone shows interest in their ad, to recover their password... "
                    . "<strong>Be careful</strong>: don't modify any of the words that appear within brackets."),
));

$aData = __get('aEmails');

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_page_head(__('Emails templates')); ?>
    <div id="email-templates" class="table-contains-actions">
        <table class="table" cellpadding="0" cellspacing="0">
            <thead>
            <tr>
                <th class="col-name"><?php _e('Name'); ?></th>
                <th class="col-title"><?php _e('Title'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($aData['aaData']) > 0) { ?>
                <?php foreach ($aData['aaData'] as $array) { ?>
                    <tr>
                        <td data-col-name="<?php echo osc_esc_html(__('Name')); ?>">
                            <?php echo $array[0]; ?>
                        </td>
                        <td data-col-name="<?php echo osc_esc_html(__('Title')); ?>">
                            <?php echo $array[1]; ?>
                        </td>
                    </tr>
                <?php } ?>
            <?php } else { ?>
                <?php osc_admin_table_empty(2, array(
                    'icon'  => 'bi-envelope',
                    'title' => __('No email templates'),
                    'text'  => __('Email templates are registered by the core and by installed plugins.'),
                )); ?>
            <?php } ?>
            </tbody>
        </table>
        <div id="table-row-actions"></div> <!-- used for table actions -->
    </div>
<?php
osc_admin_pagination($aData);
?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>