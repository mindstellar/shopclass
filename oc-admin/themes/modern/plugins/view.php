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
$file = __get('file');

// A plugin screen on a route may have declared its title, help and header actions.
$declared = osc_admin_plugin_page(Params::getParamString('route'));
osc_admin_page(array_filter(array(
    'section' => static fn () => osc_apply_filter('custom_plugin_title', __('Plugins')),
    'title'   => $declared['title'] ?? __('Plugins'),
    'help'    => $declared['help'] ?? null,
    'actions' => $declared['actions'] ?? array(),
), static fn ($value) => $value !== null && $value !== array()));

osc_current_admin_theme_path('parts/header.php'); ?>
    <!-- theme files -->
    <div class="theme-files">
        <?php
        // Already resolved by the controller, which is the only thing that renders this
        // view: an absolute path inside the plugins directory, or the view is not reached at
        // all. Do not add a traversal check here -- a partial one is worse than none, and the
        // resolution above is what the guarantee rests on.
        if (is_string($file) && $file !== '' && is_file($file)) {
            require_once $file;
        }
?>
    </div>
    <!-- /theme files -->
<?php osc_current_admin_theme_path('parts/footer.php'); ?>