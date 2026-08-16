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
$file = __get('file');

osc_admin_page(array(
    'section' => static fn () => osc_apply_filter('custom_plugin_title', __('Plugins')),
    'title'   => __('Plugins'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- theme files -->
    <div class="theme-files">
        <?php
        // Already resolved by the controller, which is the only thing that renders this
        // view: an absolute path inside the plugins directory, or the view is not reached
        // at all. The check that used to live here matched the literal '../' and compared
        // one of its two strpos() results with ==, so a path beginning '..\' — strpos 0,
        // which == false — passed the test meant to stop it.
        if (is_string($file) && $file !== '' && is_file($file)) {
            require_once $file;
        }
?>
    </div>
    <!-- /theme files -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>