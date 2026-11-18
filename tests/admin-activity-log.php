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
 * The Activity log screen: plain section and action labels (unknown codes fall back to words),
 * clean details, rows one action wrote folded into one, a from/to date filter, and Clear log
 * behind the confirm dialog and a CSRF check.
 * DB-backed.  Usage:  php tests/admin-activity-log.php
 */

require_once __DIR__ . '/lib/scratchdb.php';
require_once __DIR__ . '/lib/harness.php';

$admin = scratchdb_session('osc_admin_activity_log');

// The session lives in memory: started before anything is printed, never sent anywhere.
$_SESSION = array();

if (!defined('WEB_PATH')) {
    define('WEB_PATH', 'https://example.test/');
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}
if (!defined('PLUGINS_PATH')) {
    define('PLUGINS_PATH', ABS_PATH . 'oc-content/plugins/');
}
if (!function_exists('osc_plugins_path')) {
    function osc_plugins_path()
    {
        return PLUGINS_PATH;
    }
}
foreach (array('_m' => 'return $key;', '_e' => 'echo $key;') as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($key, $domain = "core") { ' . $body . ' }');
    }
}
if (!function_exists('osc_admin_base_url')) {
    function osc_admin_base_url($index = false)
    {
        return 'https://example.test/oc-admin/index.php';
    }
}
if (!function_exists('osc_base_url')) {
    function osc_base_url($withIndex = false)
    {
        return 'https://example.test/';
    }
}
if (!function_exists('_n')) {
    function _n($single, $plural, $n, $domain = 'core')
    {
        return $n === 1 ? $single : $plural;
    }
}
if (!function_exists('osc_current_admin_locale')) {
    function osc_current_admin_locale()
    {
        return 'en_US';
    }
}
if (!function_exists('osc_current_ui_locale')) {
    function osc_current_ui_locale(): string
    {
        return 'en_US';
    }
}
if (!function_exists('osc_logged_admin_id')) {
    function osc_logged_admin_id()
    {
        return (int) $GLOBALS['adminId'];
    }
}
// A refused token stops the request, as the real check does by exiting.
$GLOBALS['csrfRefuse'] = false;
$GLOBALS['csrfChecks'] = array();
if (!function_exists('osc_csrf_check')) {
    function osc_csrf_check($die = true)
    {
        $GLOBALS['csrfChecks'][] = Params::getParam('action');
        if ($GLOBALS['csrfRefuse']) {
            throw new RuntimeException('csrf refused');
        }

        return true;
    }
}
foreach (array('error', 'ok', 'warning', 'info') as $kind) {
    if (!function_exists('osc_add_flash_' . $kind . '_message')) {
        eval('function osc_add_flash_' . $kind . '_message($msg, $section = "pubMessages") {'
             . '$GLOBALS["flashes"][] = "' . $kind . ':" . $msg; }');
    }
}

require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
if (!function_exists('osc_current_admin_theme_path')) {
    function osc_current_admin_theme_path($file = '')
    {
    }
}
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hValidate.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSettings.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hApi.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hHttpCache.php';
// The security helpers, less the real CSRF check: the stand-in above records and refuses instead.
$security = tempnam(sys_get_temp_dir(), 'oschsec_') . '.php';
file_put_contents($security, str_replace('function osc_csrf_check(', 'function osc_csrf_check_unused(', (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/helpers/hSecurity.php')));
register_shutdown_function(static fn () => @unlink($security));
require_once $security;

/** The base controller, stubbed: redirects are recorded, and the view is drawn into the output. */
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
        return (int) $GLOBALS['adminId'] === (int) ($GLOBALS['moderatorId'] ?? 0);
    }

    public function redirectTo($url, $code = null)
    {
        $GLOBALS['redirects'][] = $url;
    }

    public function _exportVariableToView($key, $value)
    {
        View::getInstance()->_exportVariableToView($key, $value);
    }

    public function doView($view)
    {
        $GLOBALS['views'][] = $view;
        include ABS_PATH . 'oc-admin/themes/modern/' . $view;
    }

    protected function refuseOnDemo($redirectUrl = null)
    {
        return false;
    }
}

require_once ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php';

use mindstellar\admin\LogView;

if (!function_exists('_mn')) {
    function _mn($single, $plural, $n, $domain = 'core')
    {
        return $n === 1 ? $single : $plural;
    }
}

foreach (array('osc_admin_page', 'osc_admin_body_open', 'osc_admin_body_close', 'osc_admin_section', 'osc_admin_section_close', 'osc_admin_settings_form', 'osc_show_pagination_admin') as $fn) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '(...$a) {}');
    }
}

/** One request to the screen. */
function drive(string $action, array $query = array(), array $post = array()): array
{
    $_GET  = array('page' => 'tools', 'action' => $action) + $query;
    $_POST = $post;
    Params::init();
    $GLOBALS['redirects']  = array();
    $GLOBALS['csrfChecks'] = array();
    $GLOBALS['flashes']    = array();

    ob_start();
    $refused = false;
    try {
        (new CAdminTools())->doModel();
    } catch (RuntimeException $e) {
        $refused = $e->getMessage() === 'csrf refused';
    }

    return array(
        'html'      => (string) ob_get_clean(),
        'refused'   => $refused,
        'csrf'      => $GLOBALS['csrfChecks'],
        'redirects' => $GLOBALS['redirects'],
    );
}

harness_section('labels');
pin('a known section', 'Scheduled jobs', LogView::sectionLabel('jobs'));
pin('the old and new listing sections read the same', LogView::sectionLabel('item'), LogView::sectionLabel('itemActions'));
pin('a known action', 'Deleted photos', LogView::actionLabel('deleteResourcesFromHD'));
pin('another known action', 'Ran', LogView::actionLabel('run'));
pin('an unknown camelCase code falls back to words', 'Sync stock levels', LogView::actionLabel('syncStockLevels'));
pin('an unknown snake_case code falls back to words', 'Wallet topup', LogView::sectionLabel('wallet_topup'));

harness_section('details');
pin('a stray trailing comma goes', '2394', LogView::cleanDetails('2394,'));
pin('spaces and commas at both ends go', 'a, b', LogView::cleanDetails(' ,a, b , '));

harness_section('folding');
$mk = static fn (string $at, string $action, int $id, string $data = '') => array(
    'dt_date' => $at, 's_section' => 'item', 's_action' => $action, 'fk_i_id' => (string) $id,
    's_data' => $data, 's_ip' => '1.1.1.1', 's_who' => 'admin', 'fk_i_who_id' => '1',
);
$groups = LogView::fold(array(
    $mk('2026-11-15 10:14:45', 'deleteResourcesFromHD', 99254, '99254'),
    $mk('2026-11-15 10:14:45', 'deleteResourcesFromHD', 99254, '2395,'),
    $mk('2026-11-15 10:14:45', 'delete', 99254, 'Chair'),
    $mk('2026-11-15 10:14:44', 'delete', 99253, 'Desk'),
    $mk('2026-11-15 10:14:44', 'edit', 0),
    $mk('2026-11-15 10:14:44', 'edit', 0),
));
pin('one action becomes one group, plus the two other rows', 4, count($groups));
pin('the head is the main action, not the clean-up', 'delete', $groups[0]['head']['s_action']);
pin('the clean-up rows fold under it', 2, count($groups[0]['more']));
pin('a row with another second stays apart', 0, count($groups[1]['more']));
pin('rows with no object id never fold', array(0, 0), array(count($groups[2]['more']), count($groups[3]['more'])));

harness_section('the screen');
$log = DB_TABLE_PREFIX . 't_log';
$admin->query("TRUNCATE TABLE $log");
$ins = static function (string $at, string $section, string $action, int $id, string $data, string $who = 'admin', int $whoId = 1) use ($admin, $log): void {
    $st = $admin->prepare("INSERT INTO $log (dt_date, s_section, s_action, fk_i_id, s_data, s_ip, s_who, fk_i_who_id) VALUES (?, ?, ?, ?, ?, '1.1.1.1', ?, ?)");
    $st->bind_param('sssissi', $at, $section, $action, $id, $data, $who, $whoId);
    $st->execute();
};
$ins('2026-11-15 10:14:45', 'item', 'delete', 99254, 'Chair');
$ins('2026-11-15 10:14:45', 'itemActions', 'deleteResourcesFromHD', 99254, '2395,');
$ins('2026-11-15 10:14:45', 'itemActions', 'deleteResourcesFromHD', 99254, '99254');
$ins('2026-11-10 08:00:00', 'jobs', 'run', 0, '1 done', 'system', 0);
$ins('2026-11-01 08:00:00', 'wallet_topup', 'syncStockLevels', 0, 'old one');

$page = drive('logs');
$html = $page['html'];
check('the section shows a plain label', str_contains($html, '>Scheduled jobs</span>'));
check('the raw code stays in the tooltip', str_contains($html, 'title="itemActions"') || str_contains($html, 'title="item"'));
check('the action shows a plain label', str_contains($html, '>Ran</span>') && str_contains($html, '>Deleted</span>'));
check('an unknown code reads as words', str_contains($html, '>Sync stock levels</span>'));
check('the three rows of one delete are one row', substr_count($html, '>Deleted</span>') === 1 && substr_count($html, '>Deleted photos</span>') === 0);
check('the folded parts sit in an expander', str_contains($html, '<details class="osc-log-more"><summary>2 more steps</summary>'));
check('no stray trailing comma shows', !str_contains($html, '2395,'));
check('who stays on one line', str_contains($html, '<span class="text-nowrap">admin <span class="text-muted">#1</span></span>'));
check('Clear log sits in the toolbar, not a sticky bar', preg_match('~osc-toolbar.*data-osc-dialog-open="#logs-clear-dialog".*</div>~s', $html) === 1 && !str_contains((string) strstr($html, 'id="s-recent-activity"'), 'form-actions'));
check('the clear dialog posts logs_clear to the confirm form', str_contains($html, '<dialog id="logs-clear-dialog"') && str_contains($html, 'value="logs_clear"'));
check('the filter has from and to dates', str_contains($html, 'name="from"') && str_contains($html, 'name="to"'));

$rowsIn = static fn (string $h): int => substr_count($h, '<tr') - 1;
$all = $rowsIn($html);
$limited = drive('logs', array('from' => '2026-11-10', 'to' => '2026-11-10'));
pin('a one-day range shows only that day', 1, $rowsIn($limited['html']));
check('...and keeps the dates in the filter', str_contains($limited['html'], 'value="2026-11-10"'));
$from = drive('logs', array('from' => '2026-11-15'));
pin('from alone drops older rows', 1, $rowsIn($from['html']));
$to = drive('logs', array('to' => '2026-11-05'));
pin('to alone drops newer rows', 1, $rowsIn($to['html']));
$bad = drive('logs', array('from' => 'nonsense', 'to' => '2026-02-31'));
pin('a bad date is ignored', $all, $rowsIn($bad['html']));

harness_section('clear log');
$count = static fn (): int => (int) $admin->query("SELECT COUNT(*) FROM $log")->fetch_row()[0];
$before = $count();
$GLOBALS['csrfRefuse'] = true;
$refused = drive('logs_clear', array(), array('page' => 'tools', 'action' => 'logs_clear'));
check('a request without a valid token is stopped', $refused['refused']);
pin('...and clears nothing', $before, $count());
$GLOBALS['csrfRefuse'] = false;
$cleared = drive('logs_clear', array(), array('page' => 'tools', 'action' => 'logs_clear'));
pin('with a token it checks CSRF first', array('logs_clear'), $cleared['csrf']);
pin('...and empties the log', 0, $count());

exit(harness_result());
