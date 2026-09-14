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
 * The chrome around the declared permalinks form. The form itself -- its route, its fields,
 * their values, the disclosure they sit in, the server rules under them and the submit row
 * -- is core's, drawn from the declaration the controller saves through.
 */

$form = __get('permalinks_form');

osc_admin_page(array(
    'section' => __('Settings'),
    'title'   => __('Permalinks'),
    'help'    => __("Activate this option if you want your site's URLs to be more attractive to search engines and intelligible for users."
                    . " <strong>Be careful</strong>: depending on your hosting service, this might not work correctly."),
));

osc_current_admin_theme_path('parts/header.php'); ?>
    <div id="mail-setting">
        <!-- settings form -->
        <div id="mail-settings">
            <?php osc_admin_page_head(__('Permalinks')); ?>
            <p class="settings-lead"><?php _e('By default Shopclass uses web URLs which have question marks and lots of numbers in them. '
                     . 'However, Shopclass offers you friendly urls. This can improve the aesthetics, usability, '
                     . 'and forward-compatibility of your links'); ?></p>
            <?php osc_admin_settings_form($form['id'], $form); ?>
        </div>
        <!-- /settings form -->
    </div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
