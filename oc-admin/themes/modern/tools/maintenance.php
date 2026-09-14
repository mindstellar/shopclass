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

$maintenance = file_exists(osc_base_path() . '.maintenance');
$lockout     = osc_maintenance_lockout_enabled();
$message     = osc_sanitize_maintenance_message(
    (string)osc_get_preference(OSC_MAINTENANCE_PREF_MESSAGE, OSC_MAINTENANCE_PREF_SECTION)
);

/**
 * Filter callback for `render-wrapper`: the CSS class the page wrapper renders with.
 *
 * @return string
 */
function render_offset()
{
    return 'row-offset';
}


osc_admin_page(array(
    'section' => __('Tools'),
    'title'   => __('Maintenance'),
    'help'    => __('Put a banner on the site while you work, or take the public site down with HTTP 503. Signed-in admins always stay in.'),
));

osc_current_admin_theme_path('parts/header.php'); ?>
<div id="backup-setting">
    <div id="backup-settings">
        <?php osc_admin_page_head(__('Maintenance')); ?>
        <?php osc_admin_action_section(array(
    'intro'     => __('While maintenance mode is on, signed-in admins can still use the site. Everyone else either sees the banner below, or an HTTP 503 page if the public site is blocked.'),
    'body_html' => '<div class="' . ($maintenance ? 'callout-danger' : 'callout-success') . '">'
        . sprintf(__('Maintenance mode is: <strong>%s</strong>'), ($maintenance ? __('ON') : __('OFF')))
        . '</div>',
    'actions'   => array(
        array(
            'label'   => $maintenance ? __('Disable maintenance mode') : __('Enable maintenance mode'),
            'variant' => 'primary',
            'url'     => osc_admin_base_url(true) . '?page=tools&action=maintenance&mode='
                . ($maintenance ? 'off' : 'on') . '&' . osc_csrf_token_url(),
        ),
    ),
)); ?>

        <?php osc_admin_form_section(__('Visitors'), array('spaced' => true)); ?>
        <?php osc_admin_form_open(array(
            'page'   => 'tools',
            'action' => 'maintenance',
            'fields' => array('mode' => 'save'),
        )); ?>
            <?php osc_admin_form_row_open(__('Public site')); ?>
                <?php osc_admin_checkbox(array(
                    'id'      => 'maintenance_lockout',
                    'name'    => 'maintenance_lockout',
                    'label'   => __('Block the public site (HTTP 503)'),
                    'checked' => $lockout,
                    'help'    => __('Unchecked, visitors keep using the site and see the message below as a banner. The choice is kept when maintenance mode is turned off.'),
                )); ?>
            <?php osc_admin_form_row_close(); ?>
            <?php osc_admin_textarea(array(
                'id'    => 'maintenance_message',
                'name'  => 'maintenance_message',
                'label' => __('Message'),
                'value' => $message,
                'rows'  => 4,
                'attrs' => array('maxlength' => OSC_MAINTENANCE_MESSAGE_MAX),
                'help'  => __('Shown on the banner, and on the 503 page. Plain text only. Leave blank for the default message.'),
            )); ?>
        <?php osc_admin_form_close(array(
            array('label' => __('Save settings'), 'type' => 'submit', 'variant' => 'primary'),
        )); ?>
    </div>
</div>
<?php osc_current_admin_theme_path('parts/footer.php'); ?>
