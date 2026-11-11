<?php
/*
 * This file is part of Shopclass (Mindstellar).
 * Copyright (c) 2026 Navjot Tomer (Mindstellar) and contributors
 *
 * Distributed under the GNU General Public License v3.0 or later. See LICENSE.
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * The Notices screen: route, moderator access, tab handling, both tabs with counts, the
 * "Comes back" date, escaping, and the empty lines.
 *
 * Usage: php tests/admin-notices-screen.php
 */

declare(strict_types=1);

if (!defined('ABS_PATH')) {
    define('ABS_PATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
if (!defined('OC_ADMIN')) {
    define('OC_ADMIN', true);
}

foreach (['__' => 'return $s;', '_n' => 'return $n === 1 ? $s : $p;'] as $fn => $body) {
    if (!function_exists($fn)) {
        eval('function ' . $fn . '($s, $p = "", $n = 0) { ' . $body . ' }');
    }
}
if (!function_exists('_e')) {
    function _e($s)
    {
        echo $s;
    }
}
if (!function_exists('osc_admin_date')) {
    function osc_admin_date($date, $dateOnly = false)
    {
        return '<time>' . substr((string) $date, 0, 10) . '</time>';
    }
}
function osc_admin_page(array $opts): void
{
    $GLOBALS['page_opts'] = $opts;
}
function osc_current_admin_theme_path(string $file): void
{
}
function __get(string $key)
{
    return $GLOBALS['view'][$key] ?? null;
}

require_once dirname(__DIR__) . '/oc-includes/vendor/autoload.php';
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/admin-ui-stubs.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hSanitize.php';
require_once __DIR__ . '/lib/stubs.php';
require_once ABS_PATH . 'oc-admin/themes/modern/parts/ui.php';
require_once ABS_PATH . 'oc-includes/osclass/helpers/hAdminUi.php';
require_once ABS_PATH . 'oc-includes/classes/core/base/SharedInstance.php';
require_once ABS_PATH . 'oc-includes/osclass/classes/AdminMenu.php';

use mindstellar\admin\AdminNotice;
use mindstellar\admin\ModeratorAccess;
use mindstellar\routing\PageRoutes;

$root       = ABS_PATH;
$template   = $root . 'oc-admin/themes/modern/notices/index.php';

harness_section('route and access');
pin('the page is routed', 'CAdminNotices', PageRoutes::admin()['notices'] ?? null);
check('moderators may open it', in_array('notices', ModeratorAccess::DEFAULT_PAGES, true));

harness_section('template');

$now    = 1_800_000_000;
$action = new AdminNotice('backups_open', 'error', 'Backups are open to the web.', 'Anyone can download them.', 'https://example.test/oc-admin/?page=tools', 'Fix it', 'tools', 'tools_backup', 'System info', false, $now - 86400);
$head   = new AdminNotice('x:test', 'warning', '<b>Stripe</b> is in test mode.', '', '', '', 'plugins', '', 'Stripe', false, $now - 3600);
$snoozed = new AdminNotice('jobs_failed', 'error', 'Jobs stopped.', '', 'https://example.test/jobs', 'Open jobs', 'tools', '', 'System info', false, $now - 7200, $now + 5 * 86400);

function screen(string $tab, array $active, array $hidden): string
{
    global $template;
    $GLOBALS['view'] = ['tab' => $tab, 'active' => $active, 'hidden' => $hidden];
    ob_start();
    include $template;

    return squeeze((string) ob_get_clean());
}

harness_section('Active tab');
$out = screen('active', [$action, $head], [$snoozed]);
check('both tabs show their counts', strpos($out, 'Active <span class="osc-count">2</span>') !== false || (bool) preg_match('~Active\s*<span class="osc-count">2</span>~', $out));
check('the hidden count is on the Hidden tab', (bool) preg_match('~Hidden\s*<span class="osc-count">1</span>~', $out));
check('the Active tab is current', (bool) preg_match('~class="is-active"[^>]*aria-current="page"[^>]*>Active~', $out));
check('the action row has a Needs action badge', strpos($out, 'status-notice-action') !== false && strpos($out, 'Needs action') !== false);
check('the warning row has a Heads-up badge', strpos($out, 'status-notice-headsup') !== false);
check('the detail is shown', strpos($out, 'Anyone can download them.') !== false);
check('the action link is used', strpos($out, '>Fix it</a>') !== false);
check('a row with no link has no link, only Hide', substr_count($out, '<a href="https://example.test/oc-admin/?page=tools">') === 1 && substr_count($out, 'data-osc-notice-hide=') === 2);
check('Hide button carries the id and the text in its name', strpos($out, 'data-osc-notice-hide="backups_open"') !== false && strpos($out, 'aria-label="Hide for 7 days: Backups are open to the web."') !== false);
check('the hidden row is not on this tab', strpos($out, 'Jobs stopped.') === false);
check('the Since column is used', strpos($out, '>Since<') !== false && strpos($out, 'Comes back') === false);

$menuProp = new ReflectionProperty(AdminMenu::getInstance(), 'aMenu');
$menuProp->setValue(AdminMenu::getInstance(), ['tools' => ['Tools <i>', '#', 'tools', 'administrator'], 'plugins' => ['Plugins', '#', 'plugins', 'administrator']]);
$out = screen('active', [$action, $head], [$snoozed]);
check('the Area is the section label, as plain text', strpos($out, '>Tools </td>') !== false || strpos($out, '>Tools</td>') !== false);
check('an area with no section is blank, not an error', (bool) preg_match('~Plugins</td>~', $out));
$menuProp->setValue(AdminMenu::getInstance(), []);

harness_section('escaping');
check('no HTML comment reaches the page', strpos($out, '<!--') === false);
check('hostile text is escaped', strpos($out, '&lt;b&gt;Stripe&lt;/b&gt;') !== false && strpos($out, '<b>Stripe') === false);

harness_section('Hidden tab');
$out = screen('hidden', [$action, $head], [$snoozed]);
check('the hidden row shows with its return date', strpos($out, 'Jobs stopped.') !== false && strpos($out, '<time>' . date('Y-m-d', $now + 5 * 86400) . '</time>') !== false);
check('the column is Comes back', strpos($out, '>Comes back<') !== false);
check('Show again replaces Hide', strpos($out, 'data-osc-notice-show="jobs_failed"') !== false && strpos($out, 'data-osc-notice-hide') === false);
check('the badge says Hidden', strpos($out, 'status-notice-hidden') !== false);
check('the Hidden tab is current', (bool) preg_match('~class="is-active"[^>]*aria-current="page"[^>]*>Hidden~', $out));

harness_section('empty');
check('nothing active says so quietly', strpos(screen('active', [], []), 'Nothing needs attention.') !== false);
check('nothing hidden says so quietly', strpos(screen('hidden', [], []), 'No hidden notices.') !== false);
check('an empty screen has no table', strpos(screen('active', [], []), '<table') === false);

exit(harness_result());
