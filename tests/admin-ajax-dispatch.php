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
 * The admin ajax endpoint keeps its public surface: every action name it has always
 * answered still reaches a handler, the same actions need a CSRF token, a moderator
 * reaches the same few, and an unknown action answers as it always did. The controller
 * is driven with its base class and the session stubbed.
 *
 * DB-free.  Usage: php tests/admin-ajax-dispatch.php
 */

// The harness prints as it goes, so AjaxResponse's headers-already-sent notice is expected.
error_reporting(E_ALL & ~E_USER_NOTICE);

define('ABS_PATH', dirname(__DIR__) . '/');
define('OC_ADMIN', true);
define('OSC_DEBUG', false);

require ABS_PATH . 'oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';

use mindstellar\admin\ajax\AjaxHandler;
use mindstellar\admin\ajax\AjaxRegistry;

/** What a missing or wrong token ends in; the real check exits. */
class CsrfRefused extends RuntimeException
{
}

function osc_csrf_check()
{
    if (Params::getParam('CSRFToken') !== 'good') {
        throw new CsrfRefused();
    }
}
function osc_run_hook($hook, ...$args)
{
    $GLOBALS['hooks'][] = array($hook, $args);
}
function osc_plugins_path()
{
    return $GLOBALS['pluginsPath'];
}
require_once __DIR__ . '/lib/stubs.php';

/** The controller base class, stubbed: reads the action and the moderator flag only. */
class AdminSecBaseModel
{
    protected $action;
    protected $ajax;

    public function __construct()
    {
        $this->action = Params::getParam('action');
    }

    public function isModerator()
    {
        return $GLOBALS['moderator'];
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

/** The session, stubbed: counts the end-of-request cleanup. */
class Session
{
    public static function getInstance()
    {
        return new self();
    }

    public function _dropKeepForm()
    {
        $GLOBALS['sessionCleared']++;
    }

    public function _clearVariables()
    {
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/ajax/CAdminAjax.php';

/** Run the endpoint with $query as the request; returns its output, or how it stopped. */
function ajax_drive(array $query, bool $moderator = false): string
{
    $_GET     = $_REQUEST = $query;
    $_POST    = array();
    Params::init();
    $GLOBALS['moderator']      = $moderator;
    $GLOBALS['hooks']          = array();
    $GLOBALS['sessionCleared'] = 0;
    ob_start();
    try {
        (new CAdminAjax())->doModel();
    } catch (CsrfRefused $e) {
        ob_end_clean();

        return 'csrf refused';
    }

    return (string) ob_get_clean();
}

// Every action the endpoint answered before it was split into handlers.
$csrf = array(
    'resource_upload', 'save_admin_theme', 'save_sidebar_state', 'categories_order',
    'field_categories_post', 'delete_field', 'add_field', 'fields_order', 'add_group', 'group_post',
    'delete_group', 'form_set_fields', 'migrate_loose_fields', 'form_submission_status',
    'form_submission_delete', 'form_submissions_purge', 'enable_category', 'delete_category',
    'edit_category_post', 'test_mail', 'test_mail_template', 'order_pages', 'market_refresh',
    'market_install', 'market_update', 'market_detail', 'upgrade', 'reinstall_osclass', 'upgrade_db',
    'location_stats', 'backup_status',
);
$open = array(
    'bulk_actions', 'regions', 'cities', 'location_catalog', 'location', 'userajax', 'date_format',
    'media_list', 'runhook', 'category_edit_iframe', 'field_categories_iframe',
    'group_categories_iframe', 'custom', 'check_version', 'check_languages', 'check_themes',
    'check_plugins', 'country_slug', 'region_slug', 'city_slug', 'location_search', 'location_impact',
    'location_record', 'error_permissions',
);

harness_section('every action still has a handler');
$all = array_merge($csrf, $open);
pin('55 actions, none added or lost', 55, count($all));
$registered = AjaxRegistry::actions();
sort($registered);
$expected = $all;
sort($expected);
pin('the registry holds exactly those actions', $expected, $registered);
foreach ($all as $action) {
    $route = AjaxRegistry::route($action);
    $ok    = $route !== null && is_subclass_of($route['handler'], AjaxHandler::class)
        && (new ReflectionMethod($route['handler'], $route['method']))->isPublic();
    check("$action dispatches to a public handler method", $ok);
}
foreach ($csrf as $action) {
    check("$action needs the CSRF token", (AjaxRegistry::route($action)['csrf'] ?? false) === true);
}
foreach ($open as $action) {
    check("$action needs no token", (AjaxRegistry::route($action)['csrf'] ?? true) === false);
}

harness_section('moderators');
pin('the moderator allow-list is unchanged', array(
    'items', 'media', 'comments', 'custom', 'runhook', 'save_admin_theme', 'save_sidebar_state',
    'resource_upload', 'media_list',
), AjaxRegistry::MODERATOR_ACTIONS);
pin('a moderator asking to upgrade is refused', '{"error":"You don\'t have the necessary permissions"}', ajax_drive(array('action' => 'upgrade'), true));
pin('a moderator may run a hook', array(array('ajax_admin_ping', array())), (ajax_drive(array('action' => 'runhook', 'hook' => 'ping'), true) === '') ? $GLOBALS['hooks'] : null);

harness_section('dispatch');
pin('an unknown action answers as before', '{"error":"no action defined"}', ajax_drive(array('action' => 'nope')));
pin('...and the session is still cleaned up', 1, $GLOBALS['sessionCleared']);
pin('a missing action too', '{"error":"no action defined"}', ajax_drive(array()));
pin('an array action is not an action', '{"error":"no action defined"}', ajax_drive(array('action' => array('regions'))));
pin('bulk_actions answers nothing', '', ajax_drive(array('action' => 'bulk_actions')));
pin('a token-checked action without a token is refused', 'csrf refused', ajax_drive(array('action' => 'delete_field', 'id' => '1')));
pin('...as is upgrade_db', 'csrf refused', ajax_drive(array('action' => 'upgrade_db')));
pin('runhook with no hook says so', '{"error":"hook parameter not defined"}', ajax_drive(array('action' => 'runhook')));
ajax_drive(array('action' => 'runhook', 'hook' => 'item_edit', 'catId' => '3', 'itemId' => '9'));
pin('runhook item_edit passes both ids', array(array('item_edit', array('3', '9'))), $GLOBALS['hooks']);

harness_section('custom runs a plugin file as the controller');
$GLOBALS['pluginsPath'] = sys_get_temp_dir() . '/osc-ajax-dispatch-' . getmypid() . '/';
@mkdir($GLOBALS['pluginsPath'] . 'demo', 0777, true);
file_put_contents($GLOBALS['pluginsPath'] . 'demo/ajax.php', '<?php echo get_class($this), "|", $file;');
pin('the file runs with $this as the controller', 'CAdminAjax|demo/ajax.php', ajax_drive(array('action' => 'custom', 'ajaxfile' => 'demo/ajax.php')));
pin('a missing file is reported', '{"error":"file doesn\'t exist"}', ajax_drive(array('action' => 'custom', 'ajaxfile' => 'demo/none.php')));
pin('a path out of the folder is refused', '{"error":"no valid file"}', ajax_drive(array('action' => 'custom', 'ajaxfile' => '../x.php')));
pin('no file named', '{"error":"no action defined"}', ajax_drive(array('action' => 'custom')));
@unlink($GLOBALS['pluginsPath'] . 'demo/ajax.php');
@rmdir($GLOBALS['pluginsPath'] . 'demo');
@rmdir($GLOBALS['pluginsPath']);

exit(harness_result());
