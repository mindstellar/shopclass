<?php
if (!defined('OC_ADMIN')) {
    exit('Direct access is not allowed.');
}
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The page core draws for a declared settings page (osc_register_settings_page()).
 *
 * Core owns this file, not the admin theme: a plugin that declares a page must get one on
 * every install, including those running an admin theme nobody here has seen. It is built
 * from the same helpers a hand-written screen uses, so it is indistinguishable from one.
 *
 * The values rendered are the submitted ones when a save was rejected, so a validation
 * error never costs the administrator what they typed.
 */

$page   = __get('settings_page');
$values = __get('settings_values');

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => $page['title'],
    'help'    => $page['help'],
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="settings-page-<?php echo osc_esc_html($page['id']); ?>">
    <?php osc_admin_page_head($page['title']); ?>

    <?php if ($page['intro'] !== '') { ?>
        <p class="form-intro"><?php echo osc_esc_html($page['intro']); ?></p>
    <?php } ?>

    <?php osc_admin_settings_form($page, array('values' => $values)); ?>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
