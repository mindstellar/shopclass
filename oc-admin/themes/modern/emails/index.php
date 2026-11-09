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
    'section' => __('Settings'),
    'heading' => __('Email templates'),
    'title'   => __('Email templates'),
    'help'    => __("Modify the emails your site's users receive when they join your site,"
                    . " when someone shows interest in their ad, to recover their password... "
                    . "<strong>Be careful</strong>: don't modify any of the words that appear within brackets."),
));

$aData = __get('aEmails');

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php
    $keys = array('name', 'title');
    osc_admin_data_table(array(
        'aColumns' => array_combine($keys, array(__('Name'), __('Subject'))),
        'aRows'    => array_map(static fn ($row) => array_combine($keys, array_slice($row, 0, 2)), $aData['aaData']),
    ), array(
        'wrap_id' => 'email-templates',
        'empty'   => array(
            'title' => __('No email templates'),
            'text'  => __('Email templates are registered by the core and by installed plugins.'),
        ),
    ));
    ?>
<?php
osc_admin_pagination($aData);
?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>