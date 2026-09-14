<?php
if (!defined('OC_ADMIN')) {
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
 * The chrome around the declared advanced-settings form. The form itself -- its route, its
 * fields, their values and the submit row -- is core's, drawn from the declaration the
 * controller saves through.
 */

$form = __get('advanced_form');

//customize Head
/**
 * Registered on `admin_header` for the advanced-settings screen; it emits nothing.
 *
 * @return void
 */
function customHead()
{
}

osc_add_hook('admin_header', 'customHead', 10);

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Advanced Settings'),
    'help'    => __("Change advanced configuration of your Shopclass. "
                    . "<strong>Be careful</strong> when modifying default values if you're not sure what you're doing!"),
));

osc_current_admin_theme_path('parts/header.php');
?>
<div id="general-setting">
    <!-- settings form -->
    <div id="general-settings">
        <?php osc_admin_page_head(__('Advanced Settings')); ?>
        <ul id="error_list"></ul>
        <?php osc_admin_settings_form($form['id'], $form); ?>
    </div>
    <!-- /settings form -->
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
