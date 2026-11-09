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

$form = (array) __get('messages_form');

osc_admin_page(array(
    'section' => osc_mode_text(__('Listings'), __('Products')),
    'heading' => __('Messages'),
    'title'   => __('Messages'),
    'help'    => __('Limits for the forms that send e-mail: contact, contact the seller, contact a user and share a listing.'),
));

osc_current_admin_theme_path('parts/header.php');
osc_current_admin_theme_path('messages/tabs.php'); ?>
<div id="message-settings">
    <?php osc_admin_settings_form($form['id'], array('titles' => false) + $form); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
