<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * Controllers that answer each action from its own method: every action they have always
 * answered still has its method, and any other action goes to the default one. Themes,
 * plugins and admin screens link to these action names.
 *
 * DB-free.  Usage: php tests/controller-actions.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

/** Controller => [its file, the actions it has always answered, the method for any other action, or null for none]. */
$controllers = array(
    'CWebItem'    => array('CWebItem.php', array(
        'activate', 'add_comment', 'contact', 'contact_post', 'deleteResources', 'delete_comment', 'item_add',
        'item_add_post', 'item_delete', 'item_edit', 'item_edit_post', 'mark', 'send_friend', 'send_friend_post',
    ), 'showItem'),
    'CAdminItems' => array('admin/CAdminItems.php', array(
        'bulk_actions', 'clear_reports', 'clear_stat', 'delete', 'deleteResource', 'item_edit', 'item_edit_post',
        'items_reported', 'post', 'post_item', 'settings', 'settings_post', 'status', 'status_premium', 'status_spam',
    ), 'listings'),
    'CAdminUsers' => array('admin/CAdminUsers.php', array(
        'activate', 'alerts', 'ban', 'create', 'create_ban_rule', 'create_ban_rule_post', 'create_post', 'deactivate',
        'delete', 'delete_alerts', 'delete_ban_rule', 'disable', 'edit', 'edit_ban_rule', 'edit_ban_rule_post',
        'edit_post', 'enable', 'resend_activation', 'settings', 'settings_post', 'sign_out_all', 'status_alerts',
        'user_login',
    ), 'users'),
    'CAdminLanguages' => array('admin/CAdminLanguages.php', array(
        'add', 'add_post', 'delete', 'disable_bo_selected', 'disable_selected', 'edit', 'edit_post',
        'enable_bo_selected', 'enable_selected', 'import_locations',
    ), 'languages'),
    'CAdminPlugins' => array('admin/CAdminPlugins.php', array(
        'add', 'add_post', 'admin', 'admin_post', 'configure', 'configure_post', 'delete', 'disable', 'enable',
        'error_plugin', 'install', 'renderplugin', 'uninstall',
    ), 'plugins'),
    'CAdminAppearance' => array('admin/CAdminAppearance.php', array(
        'activate', 'add', 'add_post', 'add_widget', 'add_widget_post', 'delete', 'delete_widget', 'edit_widget',
        'edit_widget_post', 'render', 'reorder_widgets_post', 'widget_create_post', 'widget_move_post', 'widgets',
    ), 'themes'),
    'CWebUser' => array('CWebUser.php', array(
        'alerts', 'api_access', 'api_access_post', 'change_email', 'change_email_post', 'change_password',
        'change_password_post', 'change_username', 'change_username_post', 'dashboard', 'delete', 'delete_post',
        'export', 'items', 'profile', 'profile_post', 'sign_out_all_post', 'unsub_alert',
    ), null),
    'CWebAjax' => array('CWebAjax.php', array(
        'ajax_upload', 'alerts', 'bulk_actions', 'check_username_availability', 'cities', 'custom',
        'custom_field_autocomplete', 'delete_image', 'location', 'location_cities', 'location_countries',
        'location_regions', 'regions', 'runhook',
    ), 'noAction'),
    'CWebLogin' => array('CWebLogin.php', array('forgot', 'forgot_post', 'login_post', 'recover', 'recover_post', 'resend'), 'loginForm'),
    'CAdminLogin' => array('admin/CAdminLogin.php', array(
        '2fa', '2fa_post', 'forgot', 'forgot_post', 'login_post', 'recover', 'recover_post',
    ), 'loginForm'),
    'CWebUserNonSecure' => array('CWebUserNonSecure.php', array(
        'activate_alert', 'change_email_confirm', 'contact_post', 'pub_profile', 'unsub_alert',
    ), 'toSignIn'),
    'CAdminItemComments' => array('admin/CAdminItemComments.php', array(
        'bulk_actions', 'comment_edit', 'comment_edit_post', 'delete', 'status',
    ), 'comments'),
    'CAdminAdmins' => array('admin/CAdminAdmins.php', array(
        '2fa_codes', '2fa_enable', '2fa_off', '2fa_setup', 'add', 'add_post', 'delete', 'edit', 'edit_post', 'sign_out_all',
    ), 'admins'),
    'CAdminPages' => array('admin/CAdminPages.php', array('add', 'add_post', 'delete', 'edit', 'edit_post'), 'pages'),
    'CWebContact' => array('CWebContact.php', array('confirm', 'confirm_post', 'contact_post', 'report', 'report_post'), 'contactForm'),
);

foreach ($controllers as $class => [$file, $expected, $fallback]) {
    require_once ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file;
    $actions = (new ReflectionClassConstant($class, 'ACTIONS'))->getValue();
    $names   = array_keys($actions);
    sort($names);

    harness_section($class);
    pin('every action it has always answered', $expected, $names);
    foreach ($actions as $action => $method) {
        check("$action has its method $method", method_exists($class, $method));
    }
    $dispatch = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file, 'doModel');
    if ($fallback === null) {
        check('any other action does nothing', strpos($dispatch, '?? null') !== false);
    } else {
        check("any other action goes to $fallback", method_exists($class, $fallback) && strpos($dispatch, "'$fallback'") !== false);
    }
}
check('the view beacon is answered before the map', strpos(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php', 'doModel'), "'view_beacon'") !== false);

exit(harness_result());
