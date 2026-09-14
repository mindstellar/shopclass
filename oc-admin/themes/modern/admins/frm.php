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
 * The chrome around the declared administrator-account form. The form itself -- its
 * route, its fields, their values and the submit row -- is core's, drawn from the
 * declaration the controller saves through, so what is on screen and what is stored
 * cannot say different things.
 */

$form = __get('admin_form');

osc_admin_page(array(
    'section' => __('Users'),
    'title'   => $form['title'],
));
?>
<?php osc_current_admin_theme_path('parts/header.php'); ?>
<?php osc_admin_page_head($form['title']); ?>
<div class="settings-user">
    <ul id="error_list"></ul>
    <?php osc_admin_settings_form($form['id'], $form); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
