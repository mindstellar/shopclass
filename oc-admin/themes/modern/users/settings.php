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

$form = __get('user_form');

osc_admin_page(array(
    'section' => __('Users'),
    'title'   => __('User Settings'),
    'help'    => __('Manage the options related to users on your site. Here, you can decide if users must register or if '
                    . 'email confirmation is necessary, among other options.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <?php osc_admin_settings_form($form['id'], $form); ?>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
