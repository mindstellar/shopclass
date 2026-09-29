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
 * Tools > System info: the tabs, the old URLs that land on them, the menu, the database
 * update and Repair posts, and the jobs, sign-in and cache actions the new tabs carry.
 *
 * No database. Usage:  php tests/admin-system-info-page.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);

define('ABS_PATH', dirname(__DIR__) . '/');

require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-includes/vendor/autoload.php';

use mindstellar\admin\SystemChecks;

$theme      = ABS_PATH . 'oc-admin/themes/modern/';
$controller = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/CAdminTools.php');
$shell      = (string) file_get_contents($theme . 'tools/system-info.php');
$database   = (string) file_get_contents($theme . 'tools/system-info/database.php');
$server     = (string) file_get_contents($theme . 'tools/system-info/server.php');
$menu       = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php');
$jobs       = (string) file_get_contents($theme . 'tools/system-info/jobs.php');
$security   = (string) file_get_contents($theme . 'tools/system-info/security.php');
$cache      = (string) file_get_contents($theme . 'tools/system-info/cache.php');
$spamView   = (string) file_get_contents($theme . 'settings/spamNbots.php');
$spamCtl    = (string) file_get_contents(ABS_PATH . 'oc-includes/osclass/classes/controller/admin/settings/CAdminSettingsSpamnBots.php');

/** The body of a case label up to the next case, or of a private method. */
$body = static function (string $name) use ($controller): string {
    if (preg_match("/case \\(?'" . preg_quote($name, '/') . "'\\)?:(.*?)(?=\\n\\s*case |\\n\\s*default:)/s", $controller, $m)) {
        return $m[1];
    }
    if (preg_match('/private (?:static )?function ' . preg_quote($name, '/') . '\(.*?\n    \}\n/s', $controller, $m)) {
        return $m[0];
    }

    return '';
};

harness_section('Tabs');

pin('the page has six tabs', array('overview', 'database', 'server', 'jobs', 'security', 'cache'), SystemChecks::TABS);
check('the tab strip reads its names from SystemChecks', strpos($shell, '$tabs   = SystemChecks::labels();') !== false);
check('the page reads ?tab= and the old info-type', strpos($body('systemInfoPage'), "SystemChecks::tab(Params::getParamString('tab'), Params::getParamString('info-type'))") !== false);
pin('info-type=php-info opens Server', 'server', SystemChecks::tab('', 'php-info'));
check('the tab partial is chosen from the checked tab only', strpos($shell, "require __DIR__ . '/system-info/' . \$tab . '.php'") !== false
    && strpos($shell, "\$tab    = (string) \$view->_get('sysinfo_tab')") !== false);
foreach (array('database', 'server', 'jobs', 'security', 'cache') as $tab) {
    check("tools/system-info/$tab.php exists", is_file($theme . "tools/system-info/$tab.php"));
}
check('the shell draws one verdict and no status pill', substr_count($shell, 'osc_admin_verdict(') === 1
    && strpos($shell, 'osc_admin_status(') === false && strpos($server, 'osc_admin_status(') === false);
foreach (array('jobs' => $jobs, 'security' => $security, 'cache' => $cache) as $name => $src) {
    check("the $name tab has no verdict box or status pill of its own", strpos($src, 'osc_admin_verdict(') === false && strpos($src, 'osc_admin_status(') === false);
    check("...and no big empty-state block", strpos($src, 'osc_admin_empty(') === false);
}
check('the masonry grid is gone from the page', strpos($shell, 'sysinfo-grid') === false && strpos($server, 'sysinfo-grid') === false);
check('oscsi_row() stays defined for plugins', strpos($shell, "if (!function_exists('oscsi_row'))") !== false);
check('...and the page no longer calls it', substr_count($shell, 'oscsi_row(') === 1);
check('the Server tab links its help from the verdict', strpos($server, 'id="server-help"') !== false);

harness_section('Old URLs');

$routes = array('database', 'system_info', 'system-info', 'upgrade', 'backup', 'import', 'import_post', 'jobs', 'cache', 'jobs_run', 'jobs_retry', 'jobs_forget', 'cache_clear');
foreach ($routes as $action) {
    check("the controller routes action=$action", (bool) preg_match("/case \\(?'" . preg_quote($action, '/') . "'\\)?:/", $controller));
}
check('action=database redirects to the Database tab', (bool) preg_match("/case 'database':\\s*(?:\\/\\/[^\\n]*\\s*)?\\\$this->redirectTo\\(self::databaseUrl\\(\\)\\);/", $controller));
check('...which is System info > Database', strpos($body('databaseUrl'), "DatabaseTools::movedTo('database')") !== false);
pin('...at this address', '?page=tools&action=system-info&tab=database', \mindstellar\admin\DatabaseTools::movedTo('database'));
check('no action lands on System info', (bool) preg_match("/case 'system-info':\\s*default:\\s*\\\$this->systemInfoPage\\(\\);/", $controller));
check('#backup and #restore on the old Database URL follow to Backup and restore', strpos($shell, "location.hash === '#backup' || location.hash === '#restore'") !== false);
check('the old Database page file is gone', !is_file($theme . 'tools/database.php'));
check('action=jobs and action=cache redirect through movedTo', (bool) preg_match("/case \\(?'cache'\\)?:\\s*case 'jobs':\\s*(?:\\/\\/[^\\n]*\\s*)?\\\$this->redirectTo\\(osc_admin_base_url\\(true\\) \\. DatabaseTools::movedTo\\(\\\$this->action\\)\\);/", $controller));
pin('...to the Jobs tab', '?page=tools&action=system-info&tab=jobs', \mindstellar\admin\DatabaseTools::movedTo('jobs'));
pin('...and the Cache tab', '?page=tools&action=system-info&tab=cache', \mindstellar\admin\DatabaseTools::movedTo('cache'));
check('the old Background jobs and Cache page files are gone', !is_file($theme . 'tools/jobs.php') && !is_file($theme . 'tools/cache.php'));
check('reopening after a restore lands on the Database tab', strpos($body('backup_reopen'), 'self::databaseUrl()') !== false);

harness_section('Menu');

check('tools_database is gone', strpos($menu, "'tools_database'") === false);
check('tools_system_info stays', strpos($menu, "'tools_system_info'") !== false);
check('Tools opens on System info', strpos($menu, "add_menu(__('Tools'), osc_admin_base_url(true) . '?page=tools&action=system-info'") !== false);
check('System info is the first Tools entry', strpos($menu, "'tools_system_info'") < strpos($menu, "'tools_backup'"));
foreach (array('tools_backup', 'tools_upgrade', 'tools_cleanup', 'tools_maintenance', 'tools_logs') as $id) {
    check("the menu keeps $id", strpos($menu, "'$id'") !== false);
}
foreach (array('tools_cache', 'tools_jobs') as $id) {
    check("$id is gone", strpos($menu, "'$id'") === false);
}

harness_section('Posts');

$post = $body('databasePost');
foreach (array("Params::getParam('upgrade')", "Params::getParam('repair')") as $what) {
    $at    = strpos($post, $what);
    $demo  = $at === false ? false : strpos($post, 'refuseOnDemo(', $at);
    $token = $at === false ? false : strpos($post, 'osc_csrf_check()', $at);
    check("$what refuses on the demo, then checks the token", $demo !== false && $token !== false && $demo < $token);
}
check('posts are read only on the Database tab', strpos($body('systemInfoPage'), "\$tab === 'database' && Params::getServerParam('REQUEST_METHOD') === 'POST'") !== false);
check('Repair is refused while an update waits', (static function (string $c): bool {
    $wait = strpos($c, 'if ($pending !== array())');
    $run  = strpos($c, 'SchemaReconciler($conn))->repair()');

    return $wait !== false && $run !== false && $wait < $run;
})($post));
check('Repair is refused when nothing is fixable', (static function (string $c): bool {
    $refuse = strpos($c, '!DatabaseTools::repairAllowed($findings, $pending)');
    $run    = strpos($c, 'SchemaReconciler($conn))->repair()');

    return $refuse !== false && $run !== false && $refuse < $run;
})($post));
check('Repair waits for a running upgrade', strpos($post, 'DatabaseTools::upgradeLock($conn)') !== false && strpos($post, '$release();') !== false);
check('the update dialog posts to the Database tab', (bool) preg_match("/'id'\\s*=> 'db-update-dialog',.*?'url'\\s*=> SystemChecks::url\\(\\\$env, 'database', 'db-update'\\)/s", $shell));
check('the Repair dialog posts to the Database tab', strpos($database, "\$self       = SystemChecks::url(\$env, 'database');") !== false);
check('the Repair dialog renders only when Repair may run', (bool) preg_match(
    '/if \(!\$hasPending && \$canRepair\) \{ \?>\s*<\?php osc_admin_confirm_dialog\(array\(\s*\'id\'\s*=> \'db-repair-dialog\'/',
    $database
));
check('the Repair button sits in the "Repair can fix these" group only', substr_count($database, "'confirm' => '#db-repair-dialog'") === 1
    && (bool) preg_match("/'repair' => !\\\$hasPending/", $database));

harness_section('Actions on the new tabs');

foreach (array('jobs_run', 'jobs_retry', 'jobs_forget', 'cache_clear') as $action) {
    $c     = $body($action);
    $demo  = strpos($c, 'refuseOnDemo(');
    $token = strpos($c, 'osc_csrf_check()');
    check("$action refuses on the demo, then checks the token", $demo !== false && $token !== false && $demo < $token);
}
foreach (array('jobs_run', 'jobs_retry', 'jobs_forget') as $action) {
    check("$action lands back on the Jobs tab", strpos($body($action), '$this->redirectTo(self::jobsUrl());') !== false);
}
check('cache_clear lands back on the Cache tab', strpos($body('cache_clear'), '$this->redirectTo(self::cacheUrl());') !== false);
check('the Jobs tab posts run, retry and forget', strpos($jobs, "'action' => 'jobs_run'") !== false
    && substr_count($jobs, "'action'     => 'jobs_retry'") === 1 && strpos($jobs, "'action' => 'jobs_retry'") !== false
    && strpos($jobs, "'action' => 'jobs_forget'") !== false);
check('...and throwing away asks first', strpos($jobs, "'data-osc-dialog-open' => '#jobs-forget-dialog'") !== false && strpos($jobs, "'id'      => 'jobs-forget-dialog'") !== false);
check('the Cache tab posts cache_clear', strpos($cache, "'action' => 'cache_clear'") !== false);
check('the cache probe trusts the write, not only the read', strpos($body('cacheAnswers'), '$cache->set($key, $value, 60) === false') !== false);

$spamBody = static function (string $name) use ($spamCtl): string {
    return preg_match("/case \\('" . preg_quote($name, '/') . "'\\):(.*?)(?=\\n\\s*case \\(|\\n        \\}\\n)/s", $spamCtl, $m) ? $m[1] : '';
};
foreach (array('login_throttle_unblock', 'login_throttle_reset') as $action) {
    $c     = $spamBody($action);
    $demo  = strpos($c, 'refuseOnDemo(');
    $token = strpos($c, 'osc_csrf_check()');
    check("$action refuses on the demo, then checks the token", $demo !== false && $token !== false && $demo < $token);
    check("...and lands on the Security tab", strpos($c, '$this->redirectTo(self::securityUrl());') !== false);
    check("...which the Security tab posts to", strpos($security, "'action'     => '$action'") !== false || strpos($security, "'action' => '$action'") !== false);
}
check('Security tab URL', strpos($spamCtl, "'?page=tools&action=system-info&tab=security#signin-activity'") !== false);
check('Spam and bots keeps its settings form', strpos($spamView, "\$forms['login_throttle']") !== false);
check('...but no longer lists who is blocked', strpos($spamView, 'login_throttle_activity') === false && strpos($spamView, 'login_throttle_unblock') === false);
check('...and links to the Security tab instead', strpos($spamView, 'tab=security#signin-activity') !== false);
check('the controller still exports the list for a replaced admin theme', strpos($spamCtl, "'login_throttle_activity'") !== false);

exit(harness_result());

/* file end: ./tests/admin-system-info-page.php */
