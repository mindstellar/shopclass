<?php

if (!defined('OC_ADMIN')) {
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

$appearanceTitle = static fn () => osc_apply_filter('custom_appearance_title', __('Appearance'));

osc_admin_page(array(
    'section' => $appearanceTitle,
    'heading' => $appearanceTitle,
    'title'   => __('Appearance'),
));

osc_current_admin_theme_path('parts/header.php');
// Theme page output stays in block flow: a gap stack would break floated columns and inline buttons.
echo '<div class="theme-files">';
if ($file !== null) {
    require_once $file;
}
echo '</div>';
osc_current_admin_theme_path('parts/footer.php');
