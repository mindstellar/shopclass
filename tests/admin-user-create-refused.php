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
 * Admin Add user: a refused save goes back to the Add user form with what was typed kept,
 * and the message names the rule.
 * DB-backed. Usage: php tests/admin-user-create-refused.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_user_create_refused');

if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
foreach (['_m' => 'return $key;', '_e' => 'echo $key;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
    }
}
foreach ([
    'osc_admin_base_url'      => 'return "https://example.test/oc-admin/index.php";',
    'osc_csrf_check'          => 'return true;',
    'osc_logged_admin_id'     => 'return 1;',
    'osc_is_username_blacklisted' => 'return in_array(strtolower((string) func_get_arg(0)), ["admin", "user"], true);',
    'osc_plugins_path'        => 'return ABS_PATH . "oc-content/plugins/";',
    'osc_genRandomPassword'   => 'return bin2hex(random_bytes(8));',
    'osc_hash_password'       => 'return password_hash((string) func_get_arg(0), PASSWORD_BCRYPT, ["cost" => 4]);',
] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) { ' . $body . ' }');
    }
}
foreach (['error', 'ok', 'warning', 'info'] as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = array("' . $kind . '", $msg); }');
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';

/** The base controller, stubbed: redirects are recorded. */
class AdminSecBaseModel
{
    protected $action;

    protected $page;

    public function __construct()
    {
        $this->action = Params::getParam('action');
        $this->page   = Params::getParam('page');
    }

    public function doModel()
    {
    }

    public function isModerator()
    {
        return false;
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
    }

    public function doView($view)
    {
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminUsers.php';

/** @return array{flashes:array,redirects:array} */
function drive(array $fields): array
{
    $_GET  = ['page' => 'users', 'action' => 'create_post'];
    $_POST = $fields;
    Params::init();
    $GLOBALS['flashes']   = [];
    $GLOBALS['redirects'] = [];
    (new CAdminUsers())->doModel();

    return ['flashes' => $GLOBALS['flashes'], 'redirects' => $GLOBALS['redirects']];
}

$base = 'https://example.test/oc-admin/index.php?page=users';

harness_section('a reserved username');
$r = drive(['s_name' => 'Sam', 's_username' => 'admin', 's_email' => 'sam@example.test', 's_password' => 'longenough1', 's_password2' => 'longenough1']);
pin('goes back to the Add user form', [$base . '&action=create'], $r['redirects']);
check('the message says the name is reserved', str_contains(json_encode($r['flashes']), 'That username is reserved. Choose another.'));
pin('the typed username is kept', 'admin', Session::getInstance()->_getForm('user_s_username'));
pin('and the typed name', 'Sam', Session::getInstance()->_getForm('user_s_name'));

exit(harness_result());
