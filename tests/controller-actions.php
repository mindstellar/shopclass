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
 * answered still goes to the same method, and any other action goes to the default one. Themes,
 * plugins and admin screens link to these action names.
 *
 * DB-free.  Usage: php tests/controller-actions.php
 */

define('ABS_PATH', dirname(__DIR__) . '/');

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

/** Controller => [its file, its action map, the method for any other action, or null for none]. Change it on purpose only. */
$controllers = array(
    'CWebItem' => array('CWebItem.php', array(
        'activate'         => 'activateItem',
        'add_comment'      => 'addComment',
        'contact'          => 'contactForm',
        'contact_post'     => 'contactPost',
        'deleteResources'  => 'deleteResources',
        'delete_comment'   => 'deleteComment',
        'item_add'         => 'itemAdd',
        'item_add_post'    => 'itemAddPost',
        'item_delete'      => 'deleteItem',
        'item_edit'        => 'itemEdit',
        'item_edit_post'   => 'itemEditPost',
        'mark'             => 'markItem',
        'send_friend'      => 'sendFriend',
        'send_friend_post' => 'sendFriendPost',
    ), 'showItem'),
    'CAdminItems' => array('admin/CAdminItems.php', array(
        'bulk_actions'   => 'bulkActions',
        'clear_reports'  => 'clearReports',
        'clear_stat'     => 'clearStat',
        'delete'         => 'deleteListings',
        'deleteResource' => 'deleteResource',
        'item_edit'      => 'itemEdit',
        'item_edit_post' => 'itemEditPost',
        'items_reported' => 'reported',
        'post'           => 'newItem',
        'post_item'      => 'newItemPost',
        'settings'       => 'settings',
        'settings_post'  => 'settingsPost',
        'status'         => 'setStatus',
        'status_premium' => 'setFlag',
        'status_spam'    => 'setFlag',
    ), 'listings'),
    'CAdminUsers' => array('admin/CAdminUsers.php', array(
        'activate'             => 'activateUser',
        'alerts'               => 'alerts',
        'ban'                  => 'banRules',
        'create'               => 'createForm',
        'create_ban_rule'      => 'createBanRule',
        'create_ban_rule_post' => 'createBanRulePost',
        'create_post'          => 'createPost',
        'deactivate'           => 'deactivateUser',
        'delete'               => 'deleteUsers',
        'delete_alerts'        => 'deleteAlerts',
        'delete_ban_rule'      => 'deleteBanRules',
        'disable'              => 'disableUser',
        'edit'                 => 'editForm',
        'edit_ban_rule'        => 'editBanRule',
        'edit_ban_rule_post'   => 'editBanRulePost',
        'edit_post'            => 'editPost',
        'enable'               => 'enableUser',
        'resend_activation'    => 'resendActivation',
        'settings'             => 'settings',
        'settings_post'        => 'settingsPost',
        'sign_out_all'         => 'signOutAll',
        'status_alerts'        => 'statusAlerts',
        'user_login'           => 'signInAsUser',
    ), 'users'),
    'CAdminLanguages' => array('admin/CAdminLanguages.php', array(
        'add'                 => 'addForm',
        'add_post'            => 'addPost',
        'delete'              => 'deleteSelected',
        'disable_bo_selected' => 'disableAdminSelected',
        'disable_selected'    => 'disableSelected',
        'edit'                => 'editForm',
        'edit_post'           => 'editPost',
        'enable_bo_selected'  => 'enableAdminSelected',
        'enable_selected'     => 'enableSelected',
        'import_locations'    => 'importLocations',
    ), 'languages'),
    'CAdminPlugins' => array('admin/CAdminPlugins.php', array(
        'add'            => 'addForm',
        'add_post'       => 'addPost',
        'admin'          => 'pluginAdmin',
        'admin_post'     => 'pluginAdminPost',
        'configure'      => 'configure',
        'configure_post' => 'configurePost',
        'delete'         => 'deletePlugin',
        'disable'        => 'disable',
        'enable'         => 'enable',
        'error_plugin'   => 'errorPlugin',
        'install'        => 'install',
        'renderplugin'   => 'renderPlugin',
        'uninstall'      => 'uninstall',
    ), 'plugins'),
    'CAdminAppearance' => array('admin/CAdminAppearance.php', array(
        'activate'             => 'activateTheme',
        'add'                  => 'addForm',
        'add_post'             => 'addPost',
        'add_widget'           => 'addWidgetForm',
        'add_widget_post'      => 'addWidgetPost',
        'delete'               => 'deleteTheme',
        'delete_widget'        => 'deleteWidget',
        'edit_widget'          => 'editWidgetForm',
        'edit_widget_post'     => 'editWidgetPost',
        'render'               => 'render',
        'reorder_widgets_post' => 'reorderWidgetsPost',
        'widget_create_post'   => 'createWidgetPost',
        'widget_move_post'     => 'moveWidgetPost',
        'widgets'              => 'widgets',
    ), 'themes'),
    'CWebUser' => array('CWebUser.php', array(
        'alerts'               => 'alerts',
        'api_access'           => 'apiAccessPage',
        'api_access_post'      => 'apiAccessPagePost',
        'change_email'         => 'changeEmailForm',
        'change_email_post'    => 'changeEmailPost',
        'change_password'      => 'changePasswordForm',
        'change_password_post' => 'changePasswordPost',
        'change_username'      => 'changeUsernameForm',
        'change_username_post' => 'changeUsernamePost',
        'dashboard'            => 'dashboard',
        'delete'               => 'deleteForm',
        'delete_post'          => 'deletePost',
        'export'               => 'export',
        'items'                => 'items',
        'profile'              => 'profile',
        'profile_post'         => 'profilePost',
        'sign_out_all_post'    => 'signOutAllPost',
        'unsub_alert'          => 'unsubscribeAlert',
    ), null),
    'CWebAjax' => array('CWebAjax.php', array(
        'ajax_upload'                 => 'ajaxUpload',
        'alerts'                      => 'alerts',
        'bulk_actions'                => 'bulkActions',
        'check_username_availability' => 'checkUsername',
        'cities'                      => 'cities',
        'custom'                      => 'custom',
        'custom_field_autocomplete'   => 'fieldSuggestions',
        'delete_image'                => 'deleteImage',
        'location'                    => 'location',
        'location_cities'             => 'locationCities',
        'location_countries'          => 'locationCountries',
        'location_regions'            => 'locationRegions',
        'regions'                     => 'regions',
        'runhook'                     => 'runHook',
    ), 'noAction'),
    'CWebLogin' => array('CWebLogin.php', array(
        'forgot'       => 'forgotForm',
        'forgot_post'  => 'forgotPost',
        'login_post'   => 'loginPost',
        'recover'      => 'recoverForm',
        'recover_post' => 'recoverPost',
        'resend'       => 'resendActivation',
    ), 'loginForm'),
    'CAdminLogin' => array('admin/CAdminLogin.php', array(
        '2fa'          => 'twoFactorForm',
        '2fa_post'     => 'twoFactorPost',
        'forgot'       => 'forgotForm',
        'forgot_post'  => 'forgotPost',
        'login_post'   => 'loginPost',
        'recover'      => 'recoverForm',
        'recover_post' => 'recoverPost',
    ), 'loginForm'),
    'CWebUserNonSecure' => array('CWebUserNonSecure.php', array(
        'activate_alert'       => 'activateAlert',
        'change_email_confirm' => 'confirmEmailChange',
        'contact_post'         => 'contactPost',
        'pub_profile'          => 'publicProfile',
        'unsub_alert'          => 'unsubscribeAlert',
    ), 'toSignIn'),
    'CAdminItemComments' => array('admin/CAdminItemComments.php', array(
        'bulk_actions'      => 'bulkActions',
        'comment_edit'      => 'editForm',
        'comment_edit_post' => 'editPost',
        'delete'            => 'deleteComment',
        'status'            => 'setStatus',
    ), 'comments'),
    'CAdminAdmins' => array('admin/CAdminAdmins.php', array(
        '2fa_codes'    => 'twoFactorPost',
        '2fa_enable'   => 'twoFactorPost',
        '2fa_off'      => 'twoFactorPost',
        '2fa_setup'    => 'twoFactorPost',
        'add'          => 'addForm',
        'add_post'     => 'addPost',
        'delete'       => 'deleteAdmins',
        'edit'         => 'editForm',
        'edit_post'    => 'editPost',
        'sign_out_all' => 'signOutAll',
    ), 'admins'),
    'CAdminPages' => array('admin/CAdminPages.php', array(
        'add'       => 'addForm',
        'add_post'  => 'addPost',
        'delete'    => 'deletePages',
        'edit'      => 'editForm',
        'edit_post' => 'editPost',
    ), 'pages'),
    'CWebContact' => array('CWebContact.php', array(
        'confirm'      => 'messageLink',
        'confirm_post' => 'confirmPost',
        'contact_post' => 'contactPost',
        'report'       => 'messageLink',
        'report_post'  => 'reportPost',
    ), 'contactForm'),
    'CAdminTools' => array('admin/CAdminTools.php', array(
        'backup'             => 'backupScreen',
        'backup-sql'         => 'startBackup',
        'backup-sql_file'    => 'startBackup',
        'backup-zip'         => 'startBackup',
        'backup-zip_file'    => 'startBackup',
        'backup_cancel'      => 'cancelBackup',
        'backup_delete'      => 'deleteBackup',
        'backup_dismiss'     => 'dismissBackup',
        'backup_download'    => 'downloadBackup',
        'backup_post'        => 'backupScreen',
        'backup_reopen'      => 'reopenBackup',
        'backup_restore'     => 'restoreBackup',
        'backup_start'       => 'startBackup',
        'backup_upload'      => 'uploadBackup',
        'cache'              => 'movedToSystemInfo',
        'cache_clear'        => 'clearCache',
        'category'           => 'categoryMoved',
        'category_post'      => 'recountCategories',
        'cleanup'            => 'cleanupPage',
        'cleanup_post'       => 'cleanupSave',
        'cleanup_run'        => 'runCleanup',
        'database'           => 'databaseMoved',
        'import'             => 'importMoved',
        'import_post'        => 'uploadBackup',
        'jobs'               => 'movedToSystemInfo',
        'jobs_forget'        => 'forgetJob',
        'jobs_retry'         => 'retryJob',
        'jobs_run'           => 'runJobs',
        'locations'          => 'locationsPage',
        'locations_post'     => 'recountLocations',
        'logs'               => 'logsPage',
        'logs_clear'         => 'clearLogs',
        'logs_settings_post' => 'logSettingsSave',
        'maintenance'        => 'maintenance',
        'system-info'        => 'systemInfo',
        'system_info'        => 'systemInfo',
        'upgrade'            => 'upgradePage',
        'version'            => 'versionPage',
    ), 'systemInfo'),
);

foreach ($controllers as $class => [$file, $expected, $fallback]) {
    require_once ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file;
    $actions = (new ReflectionClassConstant($class, 'ACTIONS'))->getValue();
    ksort($actions);

    harness_section($class);
    pin('every action it has always answered goes to the same method', $expected, $actions);
    foreach ($actions as $action => $method) {
        check("$action has its method $method", method_exists($class, $method));
    }
    $dispatch = harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/' . $file, 'doModel');
    if ($fallback === null) {
        check('any other action does nothing', strpos($dispatch, 'actionMethod(null)') !== false);
    } else {
        check("any other action goes to $fallback", method_exists($class, $fallback) && strpos($dispatch, "actionMethod('$fallback')") !== false);
    }
}
/** A controller with a two-action map, to drive ActionMap directly. */
final class ActionMapProbe
{
    use \mindstellar\base\ActionMap;

    private const ACTIONS = array('item_add' => 'itemAdd', 'delete' => 'remove');

    public $action;

    public function pick(?string $default): ?string
    {
        return $this->actionMethod($default);
    }
}

harness_section('ActionMap');
$probe = new ActionMapProbe();
$pick  = static function ($action, ?string $default) use ($probe): ?string {
    $probe->action = $action;

    return $probe->pick($default);
};
pin('a mapped action gives its method', array('itemAdd', 'remove'), array($pick('item_add', 'show'), $pick('delete', 'show')));
pin('an unknown, empty or missing action gives the default', array('show', 'show', 'show'), array($pick('nope', 'show'), $pick('', 'show'), $pick(null, 'show')));
pin('an action sent as a list gives the default, not an error', 'show', $pick(array('item_add'), 'show'));
pin('a map with no default gives null', null, $pick('nope', null));
check('the view beacon is answered before the map', strpos(harness_method_source(ABS_PATH . 'oc-includes/osclass/classes/controller/CWebItem.php', 'doModel'), "'view_beacon'") !== false);

exit(harness_result());
